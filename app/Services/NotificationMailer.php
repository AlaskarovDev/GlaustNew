<?php

namespace App\Services;

use App\Mail\SystemMail;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractPayment;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Reminder and daily-digest emails. Sending is idempotent: rows are claimed with
 * a conditional UPDATE (emailed_at IS NULL / last_digest_on <> today) before the
 * mail goes out, so overlapping runs cannot send the same thing twice.
 * Must run inside Tenant::runAs($company).
 */
class NotificationMailer
{
    public function __construct(private MailService $mail) {}

    public function sendDueReminders(Company $company): int
    {
        $sent = 0;
        $due = Reminder::with('user')
            ->whereNull('emailed_at')->whereNull('read_at')
            ->where('remind_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->orderBy('remind_at')->limit(500)->get()
            ->groupBy('user_id');

        foreach ($due as $reminders) {
            $user = $reminders->first()->user;

            // Claim row by row: the UPDATE's affected count says who won each row.
            $mine = $reminders->filter(
                fn (Reminder $r) => Reminder::whereKey($r->id)->whereNull('emailed_at')->update(['emailed_at' => now()]) === 1
            )->values();
            if ($mine->isEmpty() || ! $user || ! $user->is_active || ! $user->email_reminders) {
                continue;
            }

            $count = $mine->count();
            $ok = $this->mail->send($company, $user->email, new SystemMail(
                mailSubject: $count === 1 ? 'Xatırlatma: '.$mine->first()->title : "{$count} yeni xatırlatma — TradeFlow",
                heading: $count === 1 ? $mine->first()->title : "Sizin {$count} xatırlatmanız var",
                lines: ['Salam, '.$user->name.'!'],
                sections: [[
                    'title' => 'Xatırlatmalar',
                    'items' => $mine->map(fn (Reminder $r) => [
                        'title' => $r->title,
                        'meta' => trim($r->sourceLabel().' · '.($r->body ?? '')),
                        'url' => $r->url ? url($r->url) : null,
                        'tone' => $r->remind_at->lt(today()) ? 'danger' : null,
                    ])->all(),
                ]],
                actionText: 'TradeFlow-də aç',
                actionUrl: route('my-work'),
                companyName: $company->name,
            ), 'reminder', $user->id);

            if (! $ok) {
                // Release so the next run retries (mail_logs keeps the error).
                Reminder::whereIn('id', $mine->pluck('id'))->update(['emailed_at' => null]);
            } else {
                $sent += $count;
            }
        }

        return $sent;
    }

    public function sendDigests(Company $company, bool $force = false): int
    {
        $time = (string) $company->setting('digest_time', '08:30');
        if (! $force && now()->format('H:i') < $time) {
            return 0;
        }

        $today = CarbonImmutable::today();
        $sent = 0;
        $users = User::where('company_id', $company->id)->where('is_active', true)->where('daily_digest', true)
            ->whereNull('invitation_token')
            ->where(fn ($q) => $q->whereNull('last_digest_on')->orWhere('last_digest_on', '<', $today->toDateString()))
            ->get();

        foreach ($users as $user) {
            $claimed = User::whereKey($user->id)
                ->where(fn ($q) => $q->whereNull('last_digest_on')->orWhere('last_digest_on', '<', $today->toDateString()))
                ->update(['last_digest_on' => $today->toDateString()]);
            if (! $claimed) {
                continue;
            }

            $sections = $this->digestSections($user, $today);
            if (collect($sections)->sum(fn ($s) => count($s['items'])) === 0) {
                continue; // nothing to say today — no empty mail
            }

            $ok = $this->mail->send($company, $user->email, new SystemMail(
                mailSubject: 'Gündəlik xülasə · '.$today->format('d.m.Y'),
                heading: 'Sabahınız xeyir, '.explode(' ', $user->name)[0].'!',
                lines: ['Bu gün üçün işləriniz — '.az_weekday($today).', '.$today->day.' '.az_month($today->month).'.'],
                sections: $sections,
                actionText: 'İş gününə başla',
                actionUrl: route('my-work'),
                companyName: $company->name,
                footnote: 'Gündəlik xülasə hər iş günü səhər göndərilir.',
            ), 'digest', $user->id);

            if ($ok) {
                $sent++;
            } else {
                $user->forceFill(['last_digest_on' => $user->last_digest_on])->saveQuietly();
            }
        }

        return $sent;
    }

    private function digestSections(User $user, CarbonImmutable $today): array
    {
        $taskItem = fn (Task $t, bool $late) => [
            'title' => $t->title,
            'meta' => trim(($t->project?->name ? $t->project->name.' · ' : '').'son tarix '.azdate($t->due_date).' · '.status_label('priority', $t->priority)),
            'url' => route('tasks.show', $t),
            'tone' => $late ? 'danger' : null,
        ];

        $open = Task::with('project')->open()->where('assignee_id', $user->id)->whereNotNull('due_date');
        $overdue = (clone $open)->where('due_date', '<', $today->toDateString())->orderBy('due_date')->limit(15)->get();
        $todays = (clone $open)->whereDate('due_date', $today->toDateString())->get();

        $sections = [
            ['title' => 'Bu gün', 'items' => $todays->map(fn ($t) => $taskItem($t, false))->all()],
            ['title' => 'Gecikmiş tapşırıqlar', 'items' => $overdue->map(fn ($t) => $taskItem($t, true))->all()],
        ];

        if ($user->hasPermission('contracts.view')) {
            $ending = Contract::with('counterparty')->whereIn('status', ['signed', 'active'])
                ->whereBetween('end_date', [$today->toDateString(), $today->addDays(30)->toDateString().' 23:59:59'])
                ->where(fn ($q) => $q->where('responsible_id', $user->id)->orWhereNull('responsible_id'))
                ->orderBy('end_date')->limit(10)->get();
            $sections[] = ['title' => '30 gün ərzində bitən müqavilələr', 'items' => $ending->map(fn (Contract $c) => [
                'title' => $c->number.' · '.$c->counterparty?->name,
                'meta' => 'bitir '.azdate($c->end_date).' · '.money($c->amount, $c->currency, false),
                'url' => route('contracts.show', $c),
                'tone' => $c->end_date->lte($today->addDays(7)) ? 'danger' : null,
            ])->all()];

            $payments = ContractPayment::with('contract.counterparty')->whereNull('paid_at')
                ->whereBetween('due_date', [$today->subDays(30)->toDateString(), $today->addDays(7)->toDateString().' 23:59:59'])
                ->whereHas('contract', fn ($q) => $q->whereIn('status', ['signed', 'active'])
                    ->where(fn ($w) => $w->where('responsible_id', $user->id)->orWhereNull('responsible_id')))
                ->orderBy('due_date')->limit(10)->get();
            $sections[] = ['title' => 'Ödənişlər (7 gün)', 'items' => $payments->map(fn (ContractPayment $p) => [
                'title' => money($p->amount, $p->contract->currency, false).' · '.$p->contract->number,
                'meta' => $p->contract->counterparty?->name.' · '.azdate($p->due_date),
                'url' => route('contracts.show', $p->contract),
                'tone' => $p->due_date->lt($today) ? 'danger' : null,
            ])->all()];
        }

        return $sections;
    }
}
