<?php

namespace App\Tables;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;

class BankTransactionTable extends Table
{
    protected array $searchable = ['purpose', 'reference', 'counterparty.name', 'contract.number'];

    protected array $sortable = ['date' => 'transaction_date', 'amount' => 'amount_azn'];

    public function title(): string
    {
        return 'Bank əməliyyatları';
    }

    protected function baseQuery(): Builder
    {
        return BankTransaction::query()->with(['account:id,name,bank_name,currency', 'counterparty:id,name', 'contract:id,number', 'project:id,code', 'category:id,name,color']);
    }

    public function filters(): array
    {
        return [
            'account_id' => ['label' => 'Hesab', 'type' => 'select', 'options' => BankAccount::orderBy('name')->get()->mapWithKeys(fn ($a) => [$a->id => $a->name.' ('.$a->currency.')'])->all()],
            'direction' => ['label' => 'İstiqamət', 'type' => 'select', 'options' => ['in' => 'Mədaxil', 'out' => 'Məxaric']],
            'kind' => ['label' => 'Növ', 'type' => 'select', 'options' => config('glaust.transaction_kinds')],
            'category_id' => ['label' => 'Kateqoriya', 'type' => 'select', 'options' => Category::where('scope', 'bank')->orderBy('name')->pluck('name', 'id')->all()],
            'from' => ['label' => 'Tarixdən', 'type' => 'date'],
            'to' => ['label' => 'Tarixədək', 'type' => 'date'],
        ];
    }

    protected function applyFilters(Builder $query): void
    {
        $r = $this->request;
        if ($r->filled('account_id')) {
            $query->where('bank_account_id', $r->integer('account_id'));
        }
        if (in_array($r->query('direction'), ['in', 'out'], true)) {
            $query->where('direction', $r->query('direction'));
        }
        if (array_key_exists((string) $r->query('kind'), config('glaust.transaction_kinds'))) {
            $query->where('kind', $r->query('kind'));
        }
        foreach (['category_id', 'counterparty_id', 'contract_id', 'project_id'] as $f) {
            if ($r->filled($f)) {
                $query->where($f, $r->integer($f));
            }
        }
        if ($r->filled('from')) {
            $query->where('transaction_date', '>=', $r->date('from')->toDateString());
        }
        if ($r->filled('to')) {
            $query->where('transaction_date', '<=', $r->date('to')->toDateString());
        }
    }

    public function columns(): array
    {
        return [
            Column::make('Tarix', 'transaction_date', 'date'),
            Column::make('Hesab', 'account.name'),
            Column::make('Bank', 'account.bank_name'),
            Column::make('İstiqamət', fn ($t) => $t->direction === 'in' ? 'Mədaxil' : 'Məxaric'),
            Column::make('Növ', fn ($t) => config('glaust.transaction_kinds.'.$t->kind)),
            Column::make('Məbləğ', fn ($t) => ($t->direction === 'out' ? -1 : 1) * (float) $t->amount, 'money'),
            Column::make('Valyuta', 'currency'),
            Column::make('CBAR məzənnəsi', 'cbar_rate', 'rate'),
            Column::make('Tətbiq olunan məzənnə', 'applied_rate', 'rate'),
            Column::make('AZN', fn ($t) => ($t->direction === 'out' ? -1 : 1) * (float) $t->amount_azn, 'money', total: true),
            Column::make('Kontragent', 'counterparty.name'),
            Column::make('Müqavilə', 'contract.number'),
            Column::make('Layihə', 'project.code'),
            Column::make('Kateqoriya', 'category.name'),
            Column::make('Təyinat', 'purpose', width: 40),
            Column::make('Sənəd №', 'reference'),
        ];
    }

    /** In/out totals (AZN) for the current filters. */
    public function totals(): array
    {
        $rows = $this->query()->reorder()->setEagerLoads([])->selectRaw('direction, SUM(amount_azn) as s, COUNT(*) as n')->groupBy('direction')->get()->keyBy('direction');

        return ['in' => (float) ($rows['in']->s ?? 0), 'out' => (float) ($rows['out']->s ?? 0), 'count' => (int) $rows->sum('n')];
    }
}
