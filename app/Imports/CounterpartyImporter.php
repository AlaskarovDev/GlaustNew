<?php

namespace App\Imports;

use App\Models\Counterparty;
use App\Rules\Iban;
use Illuminate\Validation\Rule;

class CounterpartyImporter extends Importer
{
    public static function type(): string
    {
        return 'counterparties';
    }

    public static function title(): string
    {
        return __('Müştəri və təchizatçılar');
    }

    public static function ability(): string
    {
        return 'crm.import';
    }

    public static function description(): string
    {
        return __('Ad, növ, VÖEN, rekvizitlər. Eyni VÖEN artıq varsa, sətir atlanır.');
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => 'Ad', 'required' => true, 'aliases' => ['adı', 'şirkət', 'kontragent', 'name', 'company'], 'example' => '«Xəzər Logistika» MMC'],
            'type' => ['label' => __('Növ'), 'required' => true, 'aliases' => ['tip', 'type'], 'example' => 'Müştəri'],
            'voen' => ['label' => __('VÖEN'), 'aliases' => ['voen', 'vöen', 'tin', 'inn'], 'example' => '1234567891'],
            'entity_type' => ['label' => __('Şəxs'), 'aliases' => ['hüquqi/fiziki'], 'example' => 'Hüquqi şəxs'],
            'country' => ['label' => __('Ölkə'), 'aliases' => ['country'], 'example' => 'Azərbaycan'],
            'city' => ['label' => __('Şəhər'), 'aliases' => ['city'], 'example' => 'Bakı'],
            'address' => ['label' => __('Ünvan'), 'aliases' => ['address'], 'example' => 'Nizami küç. 10'],
            'phone' => ['label' => __('Telefon'), 'aliases' => ['tel', 'phone', 'mobil'], 'example' => '+994 12 555 55 55'],
            'email' => ['label' => 'Email', 'aliases' => ['e-poçt', 'e-mail', 'mail'], 'example' => 'info@xezer.az'],
            'iban' => ['label' => 'IBAN', 'aliases' => ['hesab', 'account'], 'example' => ''],
            'bank_name' => ['label' => 'Bank', 'aliases' => ['bankın adı'], 'example' => 'Kapital Bank'],
            'swift' => ['label' => 'SWIFT', 'aliases' => ['bic', 'swift/bic'], 'example' => ''],
            'tags' => ['label' => __('Etiketlər'), 'aliases' => ['tags', 'etiket'], 'example' => 'VIP, idxal'],
            'notes' => ['label' => __('Qeyd'), 'aliases' => ['qeydlər', 'notes'], 'example' => ''],
        ];
    }

    protected function normalise(array $raw): array
    {
        $row = parent::normalise($raw);
        $row['type'] = self::option($row['type'] ?? null, config('glaust.counterparty_types'), null,
            ['alıcı' => 'customer', 'sifarişçi' => 'customer', 'satıcı' => 'supplier', 'podratçı' => 'supplier', 'daşıyıcı' => 'supplier', 'hər ikisi' => 'both']);
        $row['entity_type'] = self::option($row['entity_type'] ?? null, config('glaust.entity_types'), 'legal',
            ['hüquqi' => 'legal', 'fiziki' => 'individual', 'fərdi sahibkar' => 'individual']);
        $row['voen'] = preg_replace('/\D/', '', (string) ($row['voen'] ?? '')) ?: null;
        $row['iban'] = Iban::normalize($row['iban'] ?? null);
        $row['swift'] = strtoupper((string) ($row['swift'] ?? '')) ?: null;
        $row['country'] = ($row['country'] ?? null) ?: 'Azərbaycan';

        return $row;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', Rule::in(['customer', 'supplier', 'both'])],
            'entity_type' => ['required', Rule::in(['legal', 'individual'])],
            'voen' => ['nullable', 'digits:10', Rule::unique('counterparties', 'voen')->where('company_id', tenant()->id)],
            'email' => ['nullable', 'email'],
            'iban' => ['nullable', new Iban],
            'phone' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function persist(array $row): void
    {
        $this->validateRow($row);
        Counterparty::create(array_intersect_key($row, array_flip(array_keys($this->fields()))));
    }
}
