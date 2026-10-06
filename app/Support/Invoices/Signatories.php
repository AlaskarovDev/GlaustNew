<?php

namespace App\Support\Invoices;

use App\Models\Company;
use App\Models\Counterparty;
use App\Models\SalesDocument;

/**
 * Who signs a buyer document: «Генеральный директор Ad Soyad» on the specification (RU),
 * «General Director Ad Soyad» on the proforma, commercial invoice and packing list (EN).
 * Names come from the company settings (seller) and the buyer's CRM card.
 */
class Signatories
{
    public const PREFIX = ['ru' => 'Генеральный директор', 'en' => 'General Director'];

    public static function line(string $kind, ?string $name): string
    {
        $prefix = self::PREFIX[$kind === 'specification' ? 'ru' : 'en'];

        return trim($prefix.' '.trim((string) $name));
    }

    /** True when the text is empty or still the generated one (not typed by hand). */
    public static function generated(?string $text): bool
    {
        $t = trim((string) $text);

        return $t === '' || str_starts_with($t, self::PREFIX['ru']) || str_starts_with($t, self::PREFIX['en']);
    }

    /** Our director changed: documents not locked follow it (hand-typed signatories are kept). */
    public static function syncCompany(Company $company): int
    {
        return self::sync(SalesDocument::query(), 'seller_signatory', $company->director_name);
    }

    /** A buyer's director changed: its documents not locked follow it. */
    public static function syncCounterparty(Counterparty $cp): int
    {
        return self::sync(SalesDocument::where('counterparty_id', $cp->id), 'buyer_signatory', $cp->director_name);
    }

    private static function sync($query, string $field, ?string $name): int
    {
        $n = 0;
        foreach ($query->with('sourceInvoice')->get() as $doc) {
            if ($doc->isLocked() || ! self::generated($doc->{$field})) {
                continue;
            }
            $line = self::line($doc->kind, $name);
            if ($doc->{$field} !== $line) {
                $doc->update([$field => $line]);
                $n++;
            }
        }

        return $n;
    }
}
