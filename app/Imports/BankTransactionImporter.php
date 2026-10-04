<?php

namespace App\Imports;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Counterparty;
use App\Services\BankLedger;
use App\Services\Cbar\RateUnavailable;

/**
 * Bank statement import into one account (chosen on the upload screen).
 * Amount: either one signed "Məbləğ" column or separate "Mədaxil" / "Məxaric" columns.
 * Duplicates (same account, date, amount, direction, reference, purpose) are skipped
 * via import_hash, so re-importing an overlapping statement is safe.
 */
class BankTransactionImporter extends Importer
{
    public function __construct(private ?BankAccount $account = null) {}

    public static function type(): string
    {
        return 'bank_transactions';
    }

    public static function title(): string
    {
        return __('Bank çıxarışı');
    }

    public static function ability(): string
    {
        return 'bank.import';
    }

    public static function description(): string
    {
        return __('Seçilmiş hesaba əməliyyatlar. Təkrar sətirlər (eyni tarix, məbləğ, təyinat) atlanır. Məzənnə hər sətrin tarixinə görə CBAR-dan götürülür.');
    }

    public function needsAccount(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            'date' => ['label' => __('Tarix'), 'required' => true, 'aliases' => ['əməliyyat tarixi', 'date', 'дата'], 'example' => '01.10.2026'],
            'amount' => ['label' => __('Məbləğ'), 'aliases' => ['amount', 'сумма', 'məbləğ (+/-)'], 'example' => '-1250,00'],
            'credit' => ['label' => __('Mədaxil'), 'aliases' => ['daxilolma', 'kredit', 'credit', 'приход'], 'example' => ''],
            'debit' => ['label' => __('Məxaric'), 'aliases' => ['debet', 'debit', 'расход', 'silinmə'], 'example' => ''],
            'counterparty' => ['label' => __('Kontragent'), 'aliases' => ['alan/göndərən', 'counterparty', 'контрагент', 'voen'], 'example' => '1234567891'],
            'purpose' => ['label' => __('Təyinat'), 'aliases' => ['ödənişin təyinatı', 'purpose', 'назначение', 'description'], 'example' => 'Müqavilə üzrə ödəniş'],
            'reference' => ['label' => __('Sənəd №'), 'aliases' => ['sənəd', 'reference', 'номер', 'ref'], 'example' => '000123'],
        ];
    }

    protected function normalise(array $raw): array
    {
        $row = parent::normalise($raw);
        $row['date'] = self::date($row['date'] ?? null);
        $amount = parse_number($row['amount'] ?? null);
        $credit = parse_number($row['credit'] ?? null);
        $debit = parse_number($row['debit'] ?? null);
        if ($credit) {
            [$row['direction'], $row['value']] = ['in', abs($credit)];
        } elseif ($debit) {
            [$row['direction'], $row['value']] = ['out', abs($debit)];
        } elseif ($amount) {
            [$row['direction'], $row['value']] = [$amount < 0 ? 'out' : 'in', abs($amount)];
        } else {
            [$row['direction'], $row['value']] = [null, null];
        }

        return $row;
    }

    protected function validateRow(array $row): void
    {
        if (! $this->account) {
            throw new RowError(__('Bank hesabı seçilməyib.'));
        }
        $this->validate($row, ['date' => ['required', 'date', 'before_or_equal:today'], 'value' => ['required', 'numeric', 'gt:0']], ['value' => 'Məbləğ']);
    }

    private function hash(array $row): string
    {
        return hash('sha256', implode('|', [$this->account->id, $row['date'], $row['direction'], number_format((float) $row['value'], 2, '.', ''), $row['reference'] ?? '', mb_strtolower($row['purpose'] ?? '')]));
    }

    protected function persist(array $row): void
    {
        $this->validateRow($row);
        $hash = $this->hash($row);
        if (BankTransaction::where('import_hash', $hash)->exists()) {
            throw new RowError(__('Təkrar sətir: bu əməliyyat artıq daxil edilib.'));
        }

        $cp = null;
        if (filled($row['counterparty'] ?? null)) {
            $v = (string) $row['counterparty'];
            $digits = preg_replace('/\D/', '', $v);
            $cp = (strlen($digits) === 10 ? Counterparty::where('voen', $digits)->first() : null) ?? Counterparty::where('name', $v)->first();
        }

        try {
            app(BankLedger::class)->record($this->account, [
                'direction' => $row['direction'], 'transaction_date' => $row['date'], 'amount' => $row['value'],
                'counterparty_id' => $cp?->id, 'purpose' => mb_substr((string) ($row['purpose'] ?? ''), 0, 255) ?: null,
                'reference' => mb_substr((string) ($row['reference'] ?? ''), 0, 80) ?: null, 'import_hash' => $hash,
            ]);
        } catch (RateUnavailable $e) {
            throw new RowError($e->getMessage());
        }
    }
}
