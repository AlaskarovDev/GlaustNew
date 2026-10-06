<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractPayment;
use App\Models\Reminder;
use App\Models\Shipment;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Turns business dates into reminders. Each reminder has a dedupe key, unique per
 * company, so running the generator any number of times creates each one once.
 * Must run inside Tenant::runAs($company).
 */
class ReminderService
{
    public function generate(Company $company): int
    {
        $created = 0;
        $today = CarbonImmutable::today();
        $nine = fn (CarbonImmutable $d) => $d->setTime(9, 0);

        // Contract end: N days before (company setting, default 30/7/1).
        $days = array_map('intval', (array) $company->setting('contract_reminder_days'));
        sort($days); // nearest threshold first: 5 days left matches "7", not "30"
        if ($days) {
            $contracts = Contract::with('counterparty')
                ->whereIn('status', ['signed', 'active'])
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [$today->toDateString(), $today->addDays(max($days))->toDateString().' 23:59:59'])
                ->get();
            foreach ($contracts as $c) {
                $left = (int) $today->diffInDays($c->end_date, false);
                foreach ($days as $d) {
                    if ($left > $d) {
                        continue;
                    }
                    foreach ($this->recipients($c->responsible_id, $company) as $userId) {
                        $created += $this->put($userId, "contract-end:{$c->id}:{$d}:{$userId}", [
                            'source' => 'contract_end',
                            'title' => "Müqavilə {$c->number} ".($left === 0 ? 'bu gün bitir' : "{$left} gün sonra bitir"),
                            'body' => $c->counterparty?->name.' · bitmə tarixi '.azdate($c->end_date).($c->auto_renew ? ' · avtomatik uzadılır' : ''),
                            'url' => route('contracts.show', $c, false),
                            'remind_at' => $nine($today),
                            'remindable_type' => 'contract', 'remindable_id' => $c->id,
                        ]);
                    }
                    break; // only the nearest threshold that applies today
                }
            }
        }

        // Contract payment schedule.
        $payDays = array_map('intval', (array) $company->setting('payment_reminder_days'));
        if ($payDays) {
            $payments = ContractPayment::with('contract.counterparty')
                ->whereNull('paid_at')
                ->where('due_date', '<=', $today->addDays(max($payDays))->toDateString())
                ->where('due_date', '>=', $today->subDays(30)->toDateString())
                ->get();
            foreach ($payments as $p) {
                if (! $p->contract || in_array($p->contract->status, ['completed', 'cancelled'], true)) {
                    continue;
                }
                $left = (int) $today->diffInDays($p->due_date, false);
                $bucket = $left < 0 ? 'overdue' : (string) collect($payDays)->sort()->first(fn ($d) => $left <= $d, max($payDays));
                foreach ($this->recipients($p->contract->responsible_id, $company) as $userId) {
                    $created += $this->put($userId, "contract-pay:{$p->id}:{$bucket}:{$userId}", [
                        'source' => 'contract_payment',
                        'title' => 'Ödəniş: '.money($p->amount, $p->contract->currency).' · '.$p->contract->number,
                        'body' => $p->contract->counterparty?->name.' · '.($left < 0 ? abs($left).' gün gecikir' : ($left === 0 ? 'bu gün' : "{$left} gün qalıb")).' ('.azdate($p->due_date).')',
                        'url' => route('contracts.show', $p->contract, false),
                        'remind_at' => $nine($today),
                        'remindable_type' => 'contract', 'remindable_id' => $p->contract_id,
                    ]);
                }
            }
        }

        // Task due dates for the assignee.
        $taskDays = array_map('intval', (array) $company->setting('task_reminder_days'));
        if ($taskDays) {
            $tasks = Task::with('project')->open()->whereNotNull('assignee_id')->whereNotNull('due_date')
                ->whereBetween('due_date', [$today->toDateString(), $today->addDays(max($taskDays))->toDateString().' 23:59:59'])
                ->get();
            foreach ($tasks as $t) {
                $left = (int) $today->diffInDays($t->due_date, false);
                if (! in_array($left, $taskDays, true)) {
                    continue;
                }
                $created += $this->put($t->assignee_id, "task-due:{$t->id}:{$left}:{$t->due_date->format('Ymd')}", [
                    'source' => 'task_due',
                    'title' => ($left === 0 ? 'Bu gün son tarix: ' : 'Sabah son tarix: ').$t->title,
                    'body' => trim(($t->project?->name ? $t->project->name.' · ' : '').status_label('priority', $t->priority).' prioritet'),
                    'url' => route('tasks.show', $t, false),
                    'remind_at' => $nine($today),
                    'remindable_type' => 'task', 'remindable_id' => $t->id,
                ]);
            }
        }

        // Shipments past their ETA.
        $late = Shipment::whereNotIn('status', ['arrived', 'delivered'])->whereNotNull('eta')
            ->where('eta', '<', $today->toDateString())->get();
        foreach ($late as $s) {
            foreach ($this->recipients($s->responsible_id, $company) as $userId) {
                $created += $this->put($userId, "shipment-late:{$s->id}:{$s->eta->format('Ymd')}:{$userId}", [
                    'source' => 'shipment_delay',
                    'title' => "Yük {$s->number} gecikir",
                    'body' => "{$s->origin} → {$s->destination} · gözlənilən çatma ".azdate($s->eta).' · '.status_label('shipment', $s->status),
                    'url' => route('shipments.show', $s, false),
                    'remind_at' => $nine($today),
                    'remindable_type' => 'shipment', 'remindable_id' => $s->id,
                ]);
            }
        }

        return $created;
    }

    /** Header "my day" payload for one user. */
    public function myDay(User $user): array
    {
        $today = CarbonImmutable::today();
        $base = Task::with('project:id,name,code')->open()->where('assignee_id', $user->id)->whereNotNull('due_date');

        $map = fn ($t) => [
            'id' => $t->id,
            'title' => $t->title,
            'project' => $t->project?->name,
            'due' => azdate($t->due_date),
            'priority' => status_label('priority', $t->priority),
            'priority_color' => status_color('priority', $t->priority),
            'url' => route('tasks.show', $t),
            'done' => false,
        ];

        $overdue = (clone $base)->where('due_date', '<', $today->toDateString())->orderBy('due_date')->limit(20)->get()->map($map);
        $todayTasks = (clone $base)->whereDate('due_date', $today->toDateString())->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->get()->map($map);
        $upcoming = (clone $base)->whereBetween('due_date', [$today->addDay()->toDateString(), $today->addDays(3)->toDateString().' 23:59:59'])->orderBy('due_date')->limit(10)->get()->map($map);

        $reminders = Reminder::activeFor($user)->orderBy('remind_at')->limit(30)->get()->map(fn (Reminder $r) => [
            'id' => $r->id,
            'title' => $r->title,
            'body' => $r->body,
            'url' => $r->url ? url($r->url) : null,
            'source' => $r->sourceLabel(),
            'when' => $r->remind_at->isToday() ? $r->remind_at->format('H:i') : azdate($r->remind_at),
            'overdue' => $r->isOverdue(),
        ]);

        return [
            'tasks' => ['overdue' => $overdue, 'today' => $todayTasks, 'upcoming' => $upcoming],
            'reminders' => $reminders,
            'counts' => [
                'tasks' => $overdue->count() + $todayTasks->count(),
                'overdue' => $overdue->count(),
                'reminders' => $reminders->count(),
            ],
        ];
    }

    /** Responsible user, else the company admins. @return int[] */
    private function recipients(?int $responsibleId, Company $company): array
    {
        if ($responsibleId && User::where('id', $responsibleId)->where('company_id', $company->id)->where('is_active', true)->exists()) {
            return [$responsibleId];
        }

        return User::where('company_id', $company->id)->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('is_admin', true))
            ->pluck('id')->all();
    }

    private function put(int $userId, string $key, array $data): int
    {
        if (Reminder::where('dedupe_key', $key)->exists()) {
            return 0;
        }
        try {
            Reminder::create($data + ['user_id' => $userId, 'dedupe_key' => mb_substr($key, 0, 190)]);

            return 1;
        } catch (QueryException) {
            return 0; // a concurrent run inserted it first (unique index)
        }
    }
}
