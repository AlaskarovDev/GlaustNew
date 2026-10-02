<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Counterparty extends Model
{
    use Auditable, BelongsToCompany, HasAttachments, SoftDeletes;

    protected $guarded = ['id', 'company_id'];

    public function contacts(): HasMany
    {
        return $this->hasMany(CounterpartyContact::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'carrier_id');
    }

    public function scopeCustomers(Builder $q): Builder
    {
        return $q->whereIn('type', ['customer', 'both']);
    }

    public function scopeSuppliers(Builder $q): Builder
    {
        return $q->whereIn('type', ['supplier', 'both']);
    }

    public function isCustomer(): bool
    {
        return in_array($this->type, ['customer', 'both'], true);
    }

    public function isSupplier(): bool
    {
        return in_array($this->type, ['supplier', 'both'], true);
    }

    public function typeLabel(): string
    {
        return config('glaust.counterparty_types.'.$this->type, $this->type);
    }

    public function tagList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->tags))));
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
