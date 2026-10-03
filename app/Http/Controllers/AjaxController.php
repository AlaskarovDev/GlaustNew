<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Shipment;
use App\Models\Task;
use App\Models\User;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use App\Services\ReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * JSON endpoints for the header and form widgets. Business errors are returned
 * as HTTP 200 + {ok:false, message}: the hosting CDN strips 4xx bodies.
 */
class AjaxController extends Controller
{
    public function ticker(CurrencyRates $rates): JsonResponse
    {
        $codes = (array) tenant()->setting('ticker_currencies');
        $data = Cache::remember('ticker:'.md5(implode(',', $codes)).':'.$rates->today()->format('Ymd'), 600, fn () => $rates->ticker($codes));
        if (! $data['ok']) {
            $data['message'] = 'Mərkəzi Bankın məzənnələri yüklənmədi.';
        }

        return response()->json($data);
    }

    public function myDay(Request $request, ReminderService $reminders): JsonResponse
    {
        return response()->json($reminders->myDay($request->user()));
    }

    public function rate(Request $request, CurrencyRates $rates): JsonResponse
    {
        $currency = strtoupper((string) $request->query('currency'));
        if (! in_array($currency, config('glaust.currencies'), true)) {
            return response()->json(['ok' => false, 'message' => 'Naməlum valyuta.']);
        }
        try {
            $day = $rates->normalize((string) $request->query('date'));
            $rate = $rates->rate($currency, $day);

            return response()->json(['ok' => true, 'rate' => $rate, 'date' => $day->format('Y-m-d')]);
        } catch (RateUnavailable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        } catch (\InvalidArgumentException) {
            return response()->json(['ok' => false, 'message' => 'Tarix düzgün deyil.']);
        }
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $user = $request->user();
        $like = '%'.$q.'%';
        $results = [];

        if ($user->can('projects.view')) {
            foreach (Project::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like))->limit(5)->get() as $p) {
                $results[] = ['group' => 'Layihə', 'label' => $p->name, 'meta' => $p->code, 'url' => route('projects.show', $p)];
            }
            foreach (Task::where('title', 'like', $like)->limit(5)->get() as $t) {
                $results[] = ['group' => 'Tapşırıq', 'label' => $t->title, 'meta' => status_label('task', $t->status), 'url' => route('tasks.show', $t)];
            }
        }
        if ($user->can('crm.view')) {
            foreach (Counterparty::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('voen', 'like', $like)->orWhere('email', 'like', $like))->limit(6)->get() as $c) {
                $results[] = ['group' => $c->isCustomer() && ! $c->isSupplier() ? 'Müştəri' : ($c->isSupplier() && ! $c->isCustomer() ? 'Təchizatçı' : 'Kontragent'), 'label' => $c->name, 'meta' => $c->voen ? 'VÖEN '.$c->voen : null, 'url' => route('counterparties.show', $c)];
            }
        }
        if ($user->can('contracts.view')) {
            foreach (Contract::with('counterparty')->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('subject', 'like', $like))->limit(5)->get() as $c) {
                $results[] = ['group' => 'Müqavilə', 'label' => $c->number.' · '.$c->subject, 'meta' => $c->counterparty?->name, 'url' => route('contracts.show', $c)];
            }
        }
        if ($user->can('logistics.view')) {
            foreach (Shipment::where(fn ($w) => $w->where('number', 'like', $like)->orWhere('container_no', 'like', $like)->orWhere('document_no', 'like', $like))->limit(5)->get() as $s) {
                $results[] = ['group' => 'Yük', 'label' => $s->number.' · '.$s->origin.' → '.$s->destination, 'meta' => status_label('shipment', $s->status), 'url' => route('shipments.show', $s)];
            }
        }

        return response()->json(['results' => array_slice($results, 0, 20)]);
    }

    public function lookup(Request $request, string $type): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $like = '%'.$q.'%';

        $results = match ($type) {
            'counterparties' => $request->user()->can('crm.view') || $request->user()->can('contracts.view') || $request->user()->can('bank.view') || $request->user()->can('logistics.view')
                ? Counterparty::query()
                    ->when($request->query('role') === 'customer', fn ($w) => $w->customers())
                    ->when($request->query('role') === 'supplier', fn ($w) => $w->suppliers())
                    ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('voen', 'like', $like)))
                    ->orderBy('name')->limit(15)->get()
                    ->map(fn ($c) => ['id' => $c->id, 'label' => $c->name, 'meta' => trim(($c->voen ? 'VÖEN '.$c->voen.' · ' : '').$c->typeLabel()), 'type' => $c->type])
                : collect(),
            'contracts' => Contract::with('counterparty')
                ->when($request->integer('counterparty_id'), fn ($w, $id) => $w->where('counterparty_id', $id))
                ->when(in_array($request->query('kind'), ['sale', 'purchase'], true), fn ($w) => $w->where('kind', $request->query('kind')))
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'like', $like)->orWhere('subject', 'like', $like)))
                ->whereNotIn('status', ['cancelled'])
                ->latest('contract_date')->limit(20)->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'label' => $c->number.' · '.$c->subject,
                    'meta' => $c->counterparty?->name.' · '.money($c->amount, $c->currency).' · '.status_label('contract', $c->status),
                    'party_id' => $c->counterparty_id,
                    'party' => $c->counterparty?->name,
                    'party_type' => $c->counterparty?->type,
                ]),
            'projects' => Project::when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('code', 'like', $like)))
                ->whereNotIn('status', ['cancelled'])->latest()->limit(15)->get()
                ->map(fn ($p) => ['id' => $p->id, 'label' => $p->name, 'meta' => $p->code]),
            'users' => User::forTenant()->where('is_active', true)
                ->when($q !== '', fn ($w) => $w->where('name', 'like', $like))
                ->orderBy('name')->limit(15)->get()
                ->map(fn ($u) => ['id' => $u->id, 'label' => $u->name, 'meta' => $u->position]),
        };

        return response()->json(['results' => $results->values()]);
    }

    /** Quick create from the contract / transaction / shipment forms. */
    public function storeCounterparty(Request $request): JsonResponse
    {
        if (! $request->user()->can('crm.create')) {
            return response()->json(['ok' => false, 'message' => 'Kontragent yaratmağa icazəniz yoxdur.']);
        }
        $request->merge(['voen' => preg_replace('/\D/', '', (string) $request->input('voen')) ?: null]);

        $validator = Validator::make($request->all(), [
            'type' => ['required', Rule::in(['customer', 'supplier', 'both'])],
            'entity_type' => ['required', Rule::in(['legal', 'individual'])],
            'name' => ['required', 'string', 'max:190'],
            'voen' => ['nullable', 'digits:10', Rule::unique('counterparties', 'voen')->where('company_id', tenant()->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
        ], ['voen.unique' => 'Bu VÖEN ilə kontragent artıq var.'], ['name' => 'Ad', 'voen' => 'VÖEN']);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()->toArray()]);
        }

        $c = Counterparty::create($validator->validated() + ['country' => 'Azərbaycan']);

        return response()->json(['ok' => true, 'item' => ['id' => $c->id, 'label' => $c->name, 'meta' => $c->typeLabel(), 'type' => $c->type]]);
    }

    public function completeTask(Request $request, Task $task): JsonResponse
    {
        if ($task->assignee_id !== $request->user()->id && ! $request->user()->can('projects.update')) {
            return response()->json(['ok' => false, 'message' => 'Bu tapşırığı dəyişməyə icazəniz yoxdur.']);
        }
        $task->update(['status' => 'done']);

        return response()->json(['ok' => true]);
    }

    public function readReminder(Request $request, Reminder $reminder): JsonResponse
    {
        abort_unless($reminder->user_id === $request->user()->id, 404);
        $reminder->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function snoozeReminder(Request $request, Reminder $reminder): JsonResponse
    {
        abort_unless($reminder->user_id === $request->user()->id, 404);
        $minutes = max(5, min(60 * 24 * 7, (int) $request->input('minutes', 60)));
        $until = $minutes >= 1440 ? now()->addDays(intdiv($minutes, 1440))->setTime(9, 0) : now()->addMinutes($minutes);
        // Re-arm the email for the new time.
        $reminder->update(['snoozed_until' => $until, 'remind_at' => $until, 'emailed_at' => null]);

        return response()->json(['ok' => true, 'until' => $until->format('d.m.Y H:i')]);
    }
}
