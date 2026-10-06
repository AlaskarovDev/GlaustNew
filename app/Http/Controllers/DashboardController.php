<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Shipment;
use App\Models\ShipmentCost;
use App\Models\Task;
use App\Services\Cbar\CurrencyRates;
use App\Services\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public const WIDGETS = [
        'kpis' => 'Əsas göstəricilər',
        'shortcuts' => 'Qısa yollar',
        'cashflow' => 'Gəlir və xərc',
        'projects' => 'Layihələrin statusu',
        'my_tasks' => 'Bugünkü işlərim',
        'budget' => 'Büdcə və faktiki xərc',
        'balances' => 'Bank hesabları',
        'logistics' => 'Logistika',
        'top' => 'TOP-5 kontragent',
        'rates' => 'Məzənnə dinamikası',
        'activity' => 'Son fəaliyyətlər',
    ];

    public function __construct(private CurrencyRates $rates) {}

    public function index(Request $request, ReminderService $reminders): View
    {
        $user = $request->user();
        $can = fn (string $a) => $user->can($a);
        $today = CarbonImmutable::today();

        $layout = $user->dashboard_layout ?? [];
        $order = array_values(array_unique(array_merge(
            array_values(array_intersect($layout['order'] ?? [], array_keys(self::WIDGETS))),
            array_keys(self::WIDGETS)
        )));
        $hidden = array_values(array_intersect($layout['hidden'] ?? [], array_keys(self::WIDGETS)));

        $data = [
            'greeting' => $this->greeting(),
            'today' => $today,
            'order' => $order,
            'hidden' => $hidden,
            'widgets' => array_map(fn ($l) => __($l), self::WIDGETS),
            'myDay' => $reminders->myDay($user),
        ];

        $data['kpis'] = array_values(array_filter([
            $can('projects.view') ? ['label' => __('Aktiv layihələr'), 'value' => Project::where('status', 'active')->count(), 'int' => true, 'icon' => 'folder', 'tone' => 'teal', 'url' => route('projects.index', ['status' => 'active']),
                'hint' => Project::where('status', 'planned')->count().__(' planlaşdırılıb')] : null,
            $can('projects.view') ? ['label' => __('Gecikən tapşırıqlar'), 'value' => Task::open()->where('due_date', '<', $today->toDateString())->count(), 'int' => true, 'icon' => 'clock', 'tone' => 'rose', 'url' => route('tasks.index', ['due' => 'overdue', 'view' => 'list']),
                'hint' => Task::open()->whereDate('due_date', $today->toDateString())->count().__(' bu gün')] : null,
            $can('contracts.view') ? ['label' => __('Aktiv müqavilələr'), 'value' => (float) Contract::whereIn('status', ['signed', 'active'])->sum('amount_azn'), 'money' => true, 'icon' => 'signature', 'tone' => 'blue', 'url' => route('contracts.index', ['status' => 'active']),
                'hint' => Contract::whereIn('status', ['signed', 'active'])->count().__(' müqavilə · AZN ekvivalenti')] : null,
            $can('bank.view') ? $this->monthFlowKpi($today) : null,
            $can('logistics.view') ? ['label' => __('Yoldakı yüklər'), 'value' => Shipment::whereIn('status', ['loading', 'in_transit', 'customs'])->count(), 'int' => true, 'icon' => 'truck', 'tone' => 'violet', 'url' => route('shipments.index', ['status' => 'in_transit']),
                'hint' => Shipment::whereNotIn('status', ['arrived', 'delivered'])->whereNotNull('eta')->where('eta', '<', $today->toDateString())->count().' gecikir'] : null,
            $can('contracts.view') ? ['label' => __('30 gündə bitən müqavilə'), 'value' => Contract::whereIn('status', ['signed', 'active'])->whereBetween('end_date', [$today->toDateString(), $today->addDays(30)->toDateString().' 23:59:59'])->count(), 'int' => true, 'icon' => 'calendar', 'tone' => 'amber', 'url' => route('contracts.index', ['ending' => '30']),
                'hint' => __('yeniləmə və ya bağlanış')] : null,
        ]));

        if ($can('bank.view')) {
            $data['cashflow'] = $this->cashflow($today);
            $data['balances'] = $this->balances();
        }
        if ($can('projects.view')) {
            $data['projectStatus'] = $this->projectStatus();
            $data['budget'] = $this->budget($can('bank.view'));
        }
        if ($can('logistics.view')) {
            $data['logistics'] = Shipment::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->all();
        }
        if ($can('bank.view') || $can('crm.view')) {
            $data['top'] = $this->topCounterparties($today);
        }
        $data['rateHistory'] = collect(['USD', 'EUR', 'RUB'])->mapWithKeys(fn ($c) => [$c => $this->rates->history($c, 30)])->all();
        $data['activity'] = $can('logs.view') || $user->isCompanyAdmin()
            ? AuditLog::with('user')->latest('created_at')->limit(10)->get()
            : AuditLog::with('user')->where('user_id', $user->id)->latest('created_at')->limit(10)->get();

        return view('dashboard', $data);
    }

    public function saveLayout(Request $request): JsonResponse
    {
        $keys = array_keys(self::WIDGETS);
        $order = array_values(array_intersect((array) $request->input('order'), $keys));
        $hidden = array_values(array_intersect((array) $request->input('hidden'), $keys));
        $request->user()->forceFill(['dashboard_layout' => ['order' => $order, 'hidden' => $hidden]])->save();

        return response()->json(['ok' => true]);
    }

    private function greeting(): string
    {
        $h = (int) now()->format('G');

        return match (true) {
            $h < 5 => __('Gecəniz xeyrə qalsın'),
            $h < 12 => __('Sabahınız xeyir'),
            $h < 18 => __('Günortanız xeyir'),
            default => __('Axşamınız xeyir'),
        };
    }

    private function monthExpr(string $column): string
    {
        return DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m', {$column})" : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    private function monthFlowKpi(CarbonImmutable $today): array
    {
        $rows = BankTransaction::where('kind', 'regular')
            ->whereBetween('transaction_date', [$today->startOfMonth()->toDateString(), $today->toDateString().' 23:59:59'])
            ->selectRaw('direction, SUM(amount_azn) as s')->groupBy('direction')->pluck('s', 'direction');
        $in = (float) ($rows['in'] ?? 0);
        $out = (float) ($rows['out'] ?? 0);

        return ['label' => __('Bu ayın daxilolmaları'), 'value' => $in, 'money' => true, 'icon' => 'arrow-down-left', 'tone' => 'green',
            'url' => route('bank.transactions.index', ['from' => $today->startOfMonth()->toDateString()]),
            'hint' => __('Məxaric: ').money($out).__(' · Fərq: ').money($in - $out)];
    }

    private function cashflow(CarbonImmutable $today): array
    {
        $from = $today->startOfMonth()->subMonths(11);
        $expr = $this->monthExpr('transaction_date');
        $rows = BankTransaction::where('kind', 'regular')->where('transaction_date', '>=', $from->toDateString())
            ->selectRaw("{$expr} as ym, direction, SUM(amount_azn) as s")
            ->groupBy('ym', 'direction')->get();

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $from->addMonths($i);
            $months[$m->format('Y-m')] = ['label' => az_month($m->month, true).($m->month === 1 || $i === 0 ? " '".$m->format('y') : ''), 'in' => 0, 'out' => 0];
        }
        foreach ($rows as $r) {
            if (isset($months[$r->ym])) {
                $months[$r->ym][$r->direction] = round((float) $r->s, 2);
            }
        }

        return [
            'categories' => array_column($months, 'label'),
            'in' => array_column($months, 'in'),
            'out' => array_column($months, 'out'),
            'net' => array_map(fn ($m) => round($m['in'] - $m['out'], 2), array_values($months)),
            'totalIn' => array_sum(array_column($months, 'in')),
            'totalOut' => array_sum(array_column($months, 'out')),
        ];
    }

    private function balances(): array
    {
        $today = $this->rates->today();
        $accounts = BankAccount::withBalance()->where('is_active', true)->orderBy('currency')->get();
        $items = [];
        $missing = [];
        $total = 0.0;
        foreach ($accounts as $a) {
            $balance = $a->currentBalance();
            $rate = $this->rates->tryRate($a->currency, $today);
            if ($rate === null) {
                $missing[] = $a->currency;
            }
            $azn = $rate !== null ? round($balance * $rate, 2) : null;
            $total += $azn ?? 0;
            $items[] = ['name' => $a->name, 'bank' => $a->bank_name, 'currency' => $a->currency, 'balance' => $balance, 'azn' => $azn];
        }

        return ['items' => $items, 'total' => $total, 'missing' => array_values(array_unique($missing))];
    }

    private function projectStatus(): array
    {
        $counts = Project::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $labels = [];
        $series = [];
        $colors = ['planned' => '#94a3b8', 'active' => '#0f9d8a', 'on_hold' => '#e9a23b', 'completed' => '#16a34a', 'cancelled' => '#e5484d'];
        $c = [];
        foreach (config('glaust.statuses.project') as $key => [$label]) {
            if (($counts[$key] ?? 0) > 0) {
                $labels[] = $label;
                $series[] = (int) $counts[$key];
                $c[] = $colors[$key];
            }
        }

        return ['labels' => $labels, 'series' => $series, 'colors' => $c, 'total' => array_sum($series)];
    }

    private function budget(bool $withActuals): array
    {
        $projects = Project::whereIn('status', ['active', 'on_hold', 'planned'])->where('budget', '>', 0)
            ->latest('updated_at')->limit(7)->get();
        $today = $this->rates->today();
        $out = ['categories' => [], 'budget' => [], 'actual' => [], 'skipped' => []];
        foreach ($projects as $p) {
            $rate = $this->rates->tryRate($p->currency, $today);
            if ($rate === null) {
                $out['skipped'][] = $p->code;

                continue;
            }
            $actual = 0.0;
            if ($withActuals) {
                $actual += (float) BankTransaction::where('project_id', $p->id)->where('direction', 'out')->where('kind', 'regular')->sum('amount_azn');
                $actual += (float) ShipmentCost::whereHas('shipment', fn ($q) => $q->where('project_id', $p->id))->sum('amount_azn');
            }
            $out['categories'][] = $p->code;
            $out['budget'][] = round((float) $p->budget * $rate, 2);
            $out['actual'][] = round($actual, 2);
        }

        return $out;
    }

    private function topCounterparties(CarbonImmutable $today): array
    {
        $rows = BankTransaction::with('counterparty:id,name')
            ->where('kind', 'regular')->whereNotNull('counterparty_id')
            ->where('transaction_date', '>=', $today->subMonths(12)->toDateString())
            ->selectRaw('counterparty_id, SUM(amount_azn) as s')
            ->groupBy('counterparty_id')->orderByDesc('s')->limit(5)->get();

        return [
            'labels' => $rows->map(fn ($r) => $r->counterparty?->name ?? '—')->all(),
            'series' => $rows->map(fn ($r) => round((float) $r->s, 2))->all(),
        ];
    }
}
