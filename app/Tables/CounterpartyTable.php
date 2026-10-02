<?php

namespace App\Tables;

use App\Models\Counterparty;
use Illuminate\Database\Eloquent\Builder;

class CounterpartyTable extends Table
{
    protected array $searchable = ['name', 'voen', 'email', 'phone', 'tags', 'city'];

    protected array $sortable = ['name' => 'name', 'created' => 'created_at', 'contracts' => 'contracts_count'];

    protected string $defaultDirection = 'asc';

    public function title(): string
    {
        return match ($this->request->query('type')) {
            'customer' => 'Müştərilər',
            'supplier' => 'Təchizatçılar',
            default => 'Kontragentlər',
        };
    }

    protected function baseQuery(): Builder
    {
        return Counterparty::query()->withCount([
            'contracts',
            'contracts as active_contracts_count' => fn ($q) => $q->whereIn('status', ['signed', 'active']),
        ])->with('contacts');
    }

    public function filters(): array
    {
        return [
            'type' => ['label' => 'Növ', 'type' => 'select', 'options' => config('glaust.counterparty_types')],
            'entity_type' => ['label' => 'Şəxs', 'type' => 'select', 'options' => config('glaust.entity_types')],
        ];
    }

    protected function applyFilters(Builder $query): void
    {
        match ($this->request->query('type')) {
            'customer' => $query->customers(),
            'supplier' => $query->suppliers(),
            'both' => $query->where('type', 'both'),
            default => null,
        };
        if (in_array($this->request->query('entity_type'), ['legal', 'individual'], true)) {
            $query->where('entity_type', $this->request->query('entity_type'));
        }
    }

    public function columns(): array
    {
        return [
            Column::make('Ad', 'name', width: 34),
            Column::make('Növ', fn ($c) => $c->typeLabel()),
            Column::make('VÖEN', 'voen'),
            Column::make('Şəxs', fn ($c) => config('glaust.entity_types.'.$c->entity_type)),
            Column::make('Ölkə', 'country'),
            Column::make('Şəhər', 'city'),
            Column::make('Telefon', 'phone'),
            Column::make('Email', 'email'),
            Column::make('IBAN', 'iban'),
            Column::make('Bank', 'bank_name'),
            Column::make('Əlaqə şəxsi', fn ($c) => $c->contacts->first()?->name),
            Column::make('Aktiv müqavilə', 'active_contracts_count', 'number'),
            Column::make('Etiketlər', 'tags'),
        ];
    }
}
