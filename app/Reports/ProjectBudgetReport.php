<?php

namespace App\Reports;

use App\Models\BankTransaction;
use App\Models\Project;
use App\Models\ShipmentCost;
use App\Services\Cbar\CurrencyRates;
use App\Tables\Column;
use Illuminate\Support\Collection;

class ProjectBudgetReport extends Report
{
    private ?Collection $data = null;

    public static function key(): string
    {
        return 'project-budget';
    }

    public static function title(): string
    {
        return 'Layihə büdcəsi və faktiki xərc';
    }

    public static function description(): string
    {
        return 'Layihələr üzrə alan və satan tərəflə müqavilələr (marja), büdcə, bank məxarici, logistika xərcləri, daxilolma və nəticə.';
    }

    public static function icon(): string
    {
        return 'target';
    }

    public static function ability(): string
    {
        return 'projects.view';
    }

    public function filters(): array
    {
        return ['status' => ['label' => 'Status', 'type' => 'select', 'options' => status_options('project')]];
    }

    public function filterSummary(): array
    {
        return $this->request->filled('status') ? ['Status: '.status_label('project', $this->request->query('status'))] : [];
    }

    private function data(): Collection
    {
        if ($this->data) {
            return $this->data;
        }
        $rates = app(CurrencyRates::class);
        $today = $rates->today();
        $projects = Project::with(['counterparty:id,name', 'supplier:id,name', 'saleContract', 'purchaseContract'])
            ->when($this->request->filled('status'), fn ($q) => $q->where('status', $this->request->query('status')))
            ->orderBy('code')->get();
        $ids = $projects->pluck('id');

        $tx = BankTransaction::whereIn('project_id', $ids)->where('kind', 'regular')
            ->selectRaw('project_id, direction, SUM(amount_azn) as s')->groupBy('project_id', 'direction')->get()->groupBy('project_id');
        $logistics = ShipmentCost::join('shipments', 'shipments.id', '=', 'shipment_costs.shipment_id')
            ->whereIn('shipments.project_id', $ids)->whereNull('shipments.deleted_at')
            ->selectRaw('shipments.project_id as pid, SUM(shipment_costs.amount_azn) as s')->groupBy('shipments.project_id')->pluck('s', 'pid');

        return $this->data = $projects->map(function (Project $p) use ($rates, $today, $tx, $logistics) {
            $rate = $rates->tryRate($p->currency, $today);
            $budget = $rate !== null ? round((float) $p->budget * $rate, 2) : null;
            $bank = (float) ($tx[$p->id] ?? collect())->where('direction', 'out')->sum('s');
            $log = (float) ($logistics[$p->id] ?? 0);
            $income = (float) ($tx[$p->id] ?? collect())->where('direction', 'in')->sum('s');
            $expense = $bank + $log;

            return [
                'code' => $p->code, 'name' => $p->name, 'client' => $p->counterparty?->name, 'supplier' => $p->supplier?->name, 'status' => status_label('project', $p->status),
                'sale_azn' => $p->contractMargin()['sale'], 'purchase_azn' => $p->contractMargin()['purchase'], 'contract_margin' => $p->contractMargin()['margin'],
                'budget' => $budget, 'bank' => $bank, 'logistics' => $log, 'expense' => $expense, 'income' => $income,
                'result' => round($income - $expense, 2),
                'used' => $budget ? round($expense / $budget * 100, 1) : null,
            ];
        });
    }

    public function columns(): array
    {
        return [
            Column::make('Kod', 'code'),
            Column::make('Layihə', 'name', width: 32),
            Column::make('Alan tərəf', 'client'),
            Column::make('Satan tərəf', 'supplier'),
            Column::make('Status', 'status'),
            Column::make('Satış müqaviləsi (AZN)', 'sale_azn', 'money', total: true),
            Column::make('Alış müqaviləsi (AZN)', 'purchase_azn', 'money', total: true),
            Column::make('Müqavilə marjası', 'contract_margin', 'money', total: true),
            Column::make('Büdcə (AZN)', 'budget', 'money', total: true),
            Column::make('Bank məxarici', 'bank', 'money', total: true),
            Column::make('Logistika', 'logistics', 'money', total: true),
            Column::make('Faktiki xərc', 'expense', 'money', total: true),
            Column::make('Büdcədən %', 'used', 'number'),
            Column::make('Daxilolma', 'income', 'money', total: true),
            Column::make('Nəticə', 'result', 'money', total: true),
        ];
    }

    public function rows(): iterable
    {
        return $this->data();
    }

    public function summary(): array
    {
        $d = $this->data();

        return [
            ['label' => 'Müqavilə marjası', 'value' => $d->sum('contract_margin'), 'money' => true, 'tone' => $d->sum('contract_margin') >= 0 ? 'success' : 'danger'],
            ['label' => 'Faktiki xərc', 'value' => $d->sum('expense'), 'money' => true, 'tone' => 'danger'],
            ['label' => 'Daxilolma', 'value' => $d->sum('income'), 'money' => true, 'tone' => 'success'],
            ['label' => 'Büdcəni aşan layihə', 'value' => $d->filter(fn ($r) => $r['used'] !== null && $r['used'] > 100)->count(), 'tone' => 'danger'],
        ];
    }

    public function chart(): ?array
    {
        $d = $this->data()->filter(fn ($r) => $r['budget'] || $r['expense'])->take(15);
        if ($d->isEmpty()) {
            return null;
        }

        return ['type' => 'bar', 'height' => 320, 'money' => true, 'colors' => ['#cbd5e1', '#e5484d', '#0f9d8a'], 'categories' => $d->pluck('code')->values()->all(),
            'series' => [['name' => 'Büdcə', 'data' => $d->pluck('budget')->values()->all()], ['name' => 'Faktiki xərc', 'data' => $d->pluck('expense')->values()->all()], ['name' => 'Daxilolma', 'data' => $d->pluck('income')->values()->all()]]];
    }
}
