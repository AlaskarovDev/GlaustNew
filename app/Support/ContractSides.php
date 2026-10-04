<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\Counterparty;
use Illuminate\Validation\ValidationException;

/**
 * Buyer side: a customer and one of ITS sale contracts. Supplier side: a supplier and
 * one of ITS purchase contracts. A contract chosen without a party brings its party along.
 * Used by projects and deals (sövdələşmələr); field names are the same in both.
 */
class ContractSides
{
    public static function rules(): array
    {
        return [
            'counterparty_id' => ['nullable', 'integer', \App\Rules\TenantExists::in('counterparties')],
            'supplier_id' => ['nullable', 'integer', \App\Rules\TenantExists::in('counterparties')],
            'sale_contract_id' => ['nullable', 'integer', \App\Rules\TenantExists::in('contracts')],
            'purchase_contract_id' => ['nullable', 'integer', \App\Rules\TenantExists::in('contracts')],
        ];
    }

    public static function attributes(): array
    {
        return [
            'counterparty_id' => 'Məhsulu alan tərəf', 'supplier_id' => 'Məhsulu satan tərəf',
            'sale_contract_id' => 'Alan tərəflə müqavilə', 'purchase_contract_id' => 'Satan tərəflə müqavilə',
        ];
    }

    /** @throws ValidationException */
    public static function check(array $data): array
    {
        $sides = [
            ['party' => 'counterparty_id', 'contract' => 'sale_contract_id', 'kind' => 'sale', 'role' => 'isCustomer',
                'roleError' => 'Məhsulu alan tərəf CRM-də müştəri olmalıdır.',
                'kindError' => 'Bu bölməyə yalnız satış müqaviləsi (müştəri ilə) seçilə bilər.'],
            ['party' => 'supplier_id', 'contract' => 'purchase_contract_id', 'kind' => 'purchase', 'role' => 'isSupplier',
                'roleError' => 'Məhsulu satan tərəf CRM-də təchizatçı (satıcı) olmalıdır.',
                'kindError' => 'Bu bölməyə yalnız alış müqaviləsi (satıcı ilə) seçilə bilər.'],
        ];

        foreach ($sides as $s) {
            if (! empty($data[$s['party']]) && ! Counterparty::find($data[$s['party']])?->{$s['role']}()) {
                throw ValidationException::withMessages([$s['party'] => $s['roleError']]);
            }
            if (empty($data[$s['contract']])) {
                continue;
            }
            $contract = Contract::findOrFail($data[$s['contract']]);
            if ($contract->kind !== $s['kind']) {
                throw ValidationException::withMessages([$s['contract'] => $s['kindError']]);
            }
            if (empty($data[$s['party']])) {
                $data[$s['party']] = $contract->counterparty_id;
            } elseif ((int) $data[$s['party']] !== $contract->counterparty_id) {
                throw ValidationException::withMessages([
                    $s['contract'] => "Müqavilə {$contract->number} seçilmiş tərəfə deyil, «{$contract->counterparty?->name}» ilə bağlanıb.",
                ]);
            }
        }

        return $data;
    }
}
