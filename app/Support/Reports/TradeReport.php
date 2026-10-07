<?php

namespace App\Support\Reports;

use App\Models\CurrencyExchange;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\LogisticsAct;
use App\Models\SalesDocument;
use App\Services\Cbar\CurrencyRates;
use Illuminate\Support\Collection;

/**
 * Yekun hesabat — the company's ATF workbook rebuilt from recorded data: one row per seller invoice
 * (one shipment), every column computed as in the sheet (its letter kept in `ref`). Seller in the
 * invoice currency (EUR), buyer in the Trade's sale currency (RUB), results in AZN.
 * Deal-level money (payments, exchanges, acts not tied to an invoice) is shared by the invoices' weight.
 * A value that cannot be known yet (no payment, no act, no rate) stays empty, and so does whatever depends on it.
 */
class TradeReport
{
    private array $memo = [];

    /** Month-end valuation: Trades not finished by this date (no act yet) are valued at its CBAR rates. */
    private ?string $asOf = null;

    /** 1C method (Закрытие месяца → Переоценка валютных средств): the month end it is computed at, null when off. */
    private ?string $oneC = null;

    public function __construct(private CurrencyRates $rates) {}

    /** Column groups, in order: key => [label, tone]. */
    public static function groups(): array
    {
        return [
            'docs' => [__('Sənədlər'), 'slate'],
            'forecast' => [__('Proqnoz — hesab verilən tarix'), 'amber'],
            'income' => [__('Alıcıdan daxilolma'), 'green'],
            'operation' => [__('Əməliyyat — satıcıya ödəniş'), 'teal'],
            'bank' => [__('Bank kursları (fakt)'), 'blue'],
            'logistics' => [__('Logistika ödənişi'), 'amber'],
            'cash' => [__('Nəticə — pul axını'), 'green'],
            'act' => [__('Akt tarixinə'), 'slate'],
            'fx' => [__('Məzənnə fərqləri və xalis mənfəət'), 'rose'],
            'tax' => [__('Məzənnə fərqi — Vergi Məcəlləsi ilə'), 'blue'],
            'onec' => [__('Məzənnə fərqi — 1C metodu'), 'teal'],
        ];
    }

    /**
     * Columns: key => [group, label, type, formula, inputs, ref, note].
     * type: text | date | rate | azn | cur:<field> (amount in the currency named by the row's field).
     */
    public static function columns(): array
    {
        $c = fn ($g, $l, $t, $f = null, $in = [], $ref = null, $note = null) => compact('g', 'l', 't', 'f', 'in', 'ref', 'note');

        return [
            'no' => $c('docs', '№', 'text', null, [], 'A', __('Sıra nömrəsi — satıcı fakturaları tarixə görə.')),
            'seller_no' => $c('docs', __('Satıcı fakturası №'), 'text', null, [], 'B', __('Satıcının (məs. Ellis) faktura nömrəsi.')),
            'seller_date' => $c('docs', __('Satıcı fakturasının tarixi'), 'date', null, [], 'C'),
            'D' => $c('docs', __('Satıcı fakturası'), 'cur:cur', null, [], 'D', __('Satıcı fakturasının məbləği, faktura valyutasında — düzəlişlərlə birlikdə.')),
            'pay_date' => $c('docs', __('Satıcıya ödəniş tarixi'), 'date', null, [], 'E', __('Satıcıya son ödənişin tarixi.')),
            'buyer_no' => $c('docs', __('Alıcı fakturası №'), 'text', null, [], 'F', __('Alıcıya (məs. Axios) proforma / Commercial Invoice nömrəsi; fakturasız məbləğdə — onun nömrəsi.')),
            'buyer_date' => $c('docs', __('Alıcı fakturasının tarixi'), 'date', null, [], 'G'),
            'H' => $c('docs', __('Alıcı fakturası'), 'cur:saleCur', null, [], 'H', __('Alıcıya fakturanın məbləği, satış valyutasında: Commercial Invoice varsa o, yoxdursa proforma.')),
            'I' => $c('docs', __('Logistika (proqnoz)'), 'cur:logCur', null, [], 'I', __('Fakturada daxil edilən logistika xərci (proqnoz və ya dəqiq).')),
            'J' => $c('docs', __('Köçürmə komissiyası'), 'cur:cur', __('Satıcıya ödənişlərin bank komissiyası; ödəniş yoxdursa — bankın qaydası ilə (0,25%, ən az 25, ən çox 300)'), ['D'], 'J'),

            'K' => $c('forecast', __('Hesab verilən tarix'), 'date', null, [], 'K', __('RUB çevirməsində seçilən tarix — alıcıya fakturanın kəsildiyi gün.')),
            'L' => $c('forecast', __('CBAR EUR'), 'rate', null, [], 'L', __('Hesab verilən tarixə CBAR kursu: 1 EUR = ? AZN.')),
            'M' => $c('forecast', __('CBAR RUB'), 'rate', null, [], 'M', __('Hesab verilən tarixə CBAR kursu: 1 RUB = ? AZN.')),
            'N' => $c('forecast', __('Proqnoz EUR'), 'rate', null, [], 'N', __('RUB çevirməsində daxil edilən proqnoz (və ya CBAR) kursu: 1 EUR = ? AZN.')),
            'O' => $c('forecast', __('Proqnoz RUB'), 'rate', null, [], 'O', __('RUB çevirməsində daxil edilən proqnoz (və ya CBAR) kursu: 1 RUB = ? AZN.')),
            'P' => $c('forecast', __('Alış, proqnozla'), 'azn', 'D × N', ['D', 'N'], 'P'),
            'Q' => $c('forecast', __('Satış, proqnozla'), 'azn', 'H × O', ['H', 'O'], 'Q'),
            'R' => $c('forecast', __('Logistika, proqnozla'), 'azn', __('I × logistika valyutasının proqnoz kursu'), ['I'], 'R'),
            'S' => $c('forecast', __('Komissiya, proqnozla'), 'azn', 'J × N', ['J', 'N'], 'S'),
            'FP' => $c('forecast', __('Proqnoz mənfəət'), 'azn', 'Q − P − R − S', ['Q', 'P', 'R', 'S'], null, __('Hesab verilən gün proqnoz kurslarla gözlənilən mənfəət.')),

            'T' => $c('income', __('Daxilolma tarixi'), 'date', null, [], 'T', __('Alıcıdan pulun daxil olduğu (son) gün.')),
            'U' => $c('income', __('CBAR EUR'), 'rate', null, [], 'U', __('Daxilolma gününə CBAR: 1 EUR = ? AZN.')),
            'V' => $c('income', __('CBAR RUB'), 'rate', null, [], 'V', __('Daxilolma gününə CBAR: 1 RUB = ? AZN (bir neçə daxilolmada — məbləğə görə orta).')),
            'W' => $c('income', __('Daxil olan, CBAR ilə'), 'azn', 'H × V', ['H', 'V'], 'W'),

            'X' => $c('operation', __('Əməliyyat tarixi'), 'date', null, [], 'X', __('Satıcıya ödəniş günü (bir neçə ödənişdə — sonuncu).')),
            'Y' => $c('operation', __('CBAR EUR'), 'rate', null, [], 'Y', __('Ödəniş gününə CBAR: 1 EUR = ? AZN (bir neçə ödənişdə — məbləğə görə orta).')),
            'Z' => $c('operation', __('CBAR RUB'), 'rate', null, [], 'Z', __('Ödəniş gününə CBAR: 1 RUB = ? AZN.')),
            'AA' => $c('operation', __('Alış, CBAR ilə'), 'azn', 'D × Y', ['D', 'Y'], 'AA'),
            'AC' => $c('operation', __('Satış, CBAR ilə'), 'azn', 'H × Z', ['H', 'Z'], 'AC'),
            'AD' => $c('operation', __('Satılan RUB, CBAR ilə'), 'azn', 'AO × Z', ['AO', 'Z'], 'AD'),
            'AE' => $c('operation', __('Əməliyyat gününə gəlir'), 'azn', 'W − AA', ['W', 'AA'], 'AE'),

            'AO' => $c('bank', __('Satılan RUB'), 'cur:saleCur', null, [], 'AO', __('Bu Trade üçün «Valyuta alış-satışı»nda satılan rubl.')),
            'AG' => $c('bank', __('Bank RUB kursu'), 'rate', null, [], 'AG', __('Rubl satışında bankın kursu: 1 RUB = ? AZN (bir neçə satışda — orta).')),
            'AP' => $c('bank', __('Alınan EUR'), 'cur:cur', null, [], 'AP', __('Bu Trade üçün «Valyuta alış-satışı»nda alınan avro.')),
            'AF' => $c('bank', __('Bank EUR kursu'), 'rate', null, [], 'AF', __('Avro alışında bankın kursu: 1 EUR = ? AZN (bir neçə alışda — orta).')),
            'AH' => $c('bank', __('Alış, bank kursu ilə'), 'azn', 'AF × D', ['AF', 'D'], 'AH'),
            'AI' => $c('bank', __('Satış, bank kursu ilə'), 'azn', 'AG × H', ['AG', 'H'], 'AI'),
            'AJ' => $c('bank', __('Komissiya, CBAR ilə'), 'azn', 'J × Y', ['J', 'Y'], 'AJ'),
            'AK' => $c('bank', __('Komissiya, bank kursu ilə'), 'azn', 'J × AF', ['J', 'AF'], 'AK'),
            'AL' => $c('bank', __('RUB məzənnə fərqi — xərc'), 'azn', 'AO × (Z − AG)', ['AO', 'Z', 'AG'], 'AL', __('Rubl CBAR-dan ucuz satılıbsa — xərc (müsbət rəqəm).')),
            'AM' => $c('bank', __('EUR məzənnə fərqi — xərc'), 'azn', 'AP × (AF − Y)', ['AP', 'AF', 'Y'], 'AM', __('Avro CBAR-dan baha alınıbsa — xərc (müsbət rəqəm).')),
            'AR' => $c('bank', __('RUB: bank − proqnoz'), 'azn', 'AI − Q', ['AI', 'Q'], 'AR', __('Rubl bankda proqnozdan nə qədər yaxşı (+) və ya pis (−) satılıb.')),
            'AS' => $c('bank', __('EUR: proqnoz − bank'), 'azn', 'P − AH', ['P', 'AH'], 'AS', __('Avro bankda proqnozdan nə qədər ucuz (+) və ya baha (−) alınıb.')),

            'AX' => $c('logistics', __('Logistikaya ödənilən'), 'cur:axCur', null, [], 'AX', __('Logistika şirkətinə köçürülən məbləğ, ödəniş valyutasında.')),
            'AY' => $c('logistics', __('Ödəniş tarixi'), 'date', null, [], 'AY'),
            'AZ' => $c('logistics', __('CBAR EUR'), 'rate', null, [], 'AZ', __('Logistika ödənişi gününə CBAR: 1 EUR = ? AZN.')),
            'BA' => $c('logistics', __('Bank kursu (1 vahid = AZN)'), 'rate', __('BC ÷ AX — ödənişlərdəki bank kursu: 1 RUB = ? AZN'), ['BC', 'AX'], 'BA'),
            'BB' => $c('logistics', __('Logistika komissiyası'), 'azn', null, [], 'BB', __('Logistika ödənişlərinin bank komissiyası, AZN.')),
            'BC' => $c('logistics', __('Logistika, AZN'), 'azn', 'AX × BA', ['AX', 'BA'], 'BC'),
            'BD' => $c('logistics', __('Logistika, EUR ekvivalenti'), 'cur:cur', 'BC ÷ AZ', ['BC', 'AZ'], 'BD'),

            'AQ' => $c('cash', __('Xalis mənfəət — pul axını'), 'azn', 'AC − AA − BC − AJ − BB', ['AC', 'AA', 'BC', 'AJ', 'BB'], 'AQ', __('Satış (CBAR) − alış (CBAR) − logistika − komissiyalar.')),
            'BE' => $c('cash', __('Gəlir — kurs fərqləri ilə'), 'azn', 'AC − AA − AL − AM − AJ − BC − BB', ['AC', 'AA', 'AL', 'AM', 'AJ', 'BC', 'BB'], 'BE', __('Pul axını, bank məzənnə fərqləri çıxılmaqla.')),

            'BF' => $c('act', __('Logistika invoysu №'), 'text', null, [], 'BF'),
            'BG' => $c('act', __('İnvoys tarixi'), 'date', null, [], 'BG'),
            'BH' => $c('act', __('Akt №'), 'text', null, [], 'BH'),
            'BI' => $c('act', __('Akt tarixi'), 'date', null, [], 'BI', __('Aktın tarixi; akt yoxdursa — logistika invoysunun tarixi.')),
            'BJ' => $c('act', __('CBAR EUR'), 'rate', null, [], 'BJ', __('Akt tarixinə CBAR: 1 EUR = ? AZN.')),
            'BK' => $c('act', __('CBAR RUB'), 'rate', null, [], 'BK', __('Akt tarixinə CBAR: 1 RUB = ? AZN.')),
            'BL' => $c('act', __('Alış, akt tarixinə'), 'azn', 'BJ × D', ['BJ', 'D'], 'BL'),
            'BM' => $c('act', __('Satış, akt tarixinə'), 'azn', 'BK × H', ['BK', 'H'], 'BM'),
            'BN' => $c('act', __('Akt tarixinə gəlir'), 'azn', 'BM − BL', ['BM', 'BL'], 'BN'),
            'BO' => $c('act', __('Logistika, akt tarixinə'), 'azn', __('AX × akt tarixinə CBAR'), ['AX'], 'BO'),
            'BP' => $c('act', __('Logistikadan sonra'), 'azn', 'BN − BO', ['BN', 'BO'], 'BP'),

            'BQ' => $c('fx', __('Satış üzrə kurs fərqi'), 'azn', 'AC − BM', ['AC', 'BM'], 'BQ', __('Akt tarixindən ödəniş gününə qədər rublun dəyişməsi: mənfi — itki.')),
            'BR' => $c('fx', __('Alış üzrə kurs fərqi'), 'azn', 'BL − AA', ['BL', 'AA'], 'BR', __('Akt tarixindən ödəniş gününə qədər avronun dəyişməsi: müsbət — qazanc.')),
            'BS' => $c('fx', __('Logistika kurs fərqi'), 'azn', 'BC − BO', ['BC', 'BO'], 'BS'),
            'BT' => $c('fx', __('XALİS MƏNFƏƏT'), 'azn', 'BP + BQ + BR − BS − AJ − BB', ['BP', 'BQ', 'BR', 'BS', 'AJ', 'BB'], 'BT', __('Pul axını ilə eyni nəticə (AQ), akt tarixi üzrə hissələrə bölünmüş.')),

            // by the «Məzənnə fərqi» module (VM 69, 13.2.12, 108.1): the act date is when goods / services pass
            'TX_SC' => $c('tax', __('Satıcı: hal'), 'text', null, [], null, __('Satıcıya ödəniş akt tarixindən əvvəldirsə — verilmiş avans, sonradırsa — alış (kreditor borcu).')),
            'TX_S' => $c('tax', __('Satıcı üzrə'), 'azn', __('D × (akt gününə CBAR − ödəniş gününə CBAR); kreditor borcunda işarə əksinədir'), ['D', 'Y', 'BJ'], null,
                __('Alış məbləği ödəniş günü (X) ilə akt günü (BI) arasındakı CBAR fərqi ilə. Müsbət — gəlir (214), mənfi — xərc (219.3). 31.12 aradadırsa, 31.12 məzənnəsi ilə yenidən qiymətləndirilir.')),
            'TX_BC' => $c('tax', __('Alıcı: hal'), 'text', null, [], null, __('Pul akt tarixindən əvvəl daxil olubsa — alınmış avans, sonra daxil olubsa — satış (debitor borcu).')),
            'TX_B' => $c('tax', __('Alıcı üzrə'), 'azn', 'H × (V − BK)', ['H', 'V', 'BK'], null,
                __('Satış məbləği daxilolma günü (T) ilə akt günü (BI) arasındakı CBAR fərqi ilə. Müsbət — gəlir (214), mənfi — xərc (219.3).')),
            'TX_L' => $c('tax', __('Logistika üzrə'), 'azn', __('Hər ödəniş: ödənilən hissə × (akt gününə CBAR − ödəniş gününə CBAR), işarə hala görə'), ['AX'], null,
                __('Logistika xidməti: ödəniş aktdan əvvəldirsə — verilmiş avans, sonradırsa — kreditor borcu; aktın valyutasında, CBAR ilə.')),
            'TX_P' => $c('tax', __('Müsbət məzənnə fərqi'), 'azn', __('Satıcı, alıcı və logistika üzrə müsbət fərqlərin cəmi'), ['TX_S', 'TX_B', 'TX_L'], '214', __('Satışdankənar gəlir (VM 13.2.12) — mənfəət bəyannaməsinin 214-cü sətri.')),
            'TX_N' => $c('tax', __('Mənfi məzənnə fərqi'), 'azn', __('Satıcı, alıcı və logistika üzrə mənfi fərqlərin cəmi'), ['TX_S', 'TX_B', 'TX_L'], '219.3', __('Gəlirlə bağlı xərc (VM 108.1) — mənfəət bəyannaməsinin 219.3-cü sətri; xərc məbləği kimi göstərilir.')),
            'TX' => $c('tax', __('Xalis məzənnə fərqi'), 'azn', __('Müsbət − mənfi'), ['TX_P', 'TX_N'], null),

            // 1C («Закрытие месяца», mənfəət hesabatının 214 / 219.3 sətirləri): every currency balance of the Trade —
            // roubles and euros on the bank, advances given / received, receivables and payables — revalued at CBAR
            // from the day it arises, at every month end in between and on the day it closes; each step is a posting
            'C1_H' => $c('onec', __('1C: hallar'), 'text', null, [], null, __('Hər tərəf üçün: pul aktdan (logistikada — invoysdan) əvvəldirsə avans, sonradırsa borc. Avanslar da yenidən qiymətləndirilir.')),
            'C1_A' => $c('onec', __('1C: bankdakı rubl'), 'azn', __('Aktiv: daxilolma → rublun satıldığı gün, hər satış ayrıca'), [], 'A',
                __('Rubl daxil olduğu gündən satıldığı günə qədər (FIFO), aradakı hər ay sonu ayrıca yazılış. Konvertasiyadan sonra hesabda qalan rubl (məs. logistikaya sonra ödənilən) daxil edilmir.')),
            'C1_B' => $c('onec', __('1C: alıcı ilə hesablaşma'), 'azn', __('Pul aktdan əvvəl: alınmış avans (öhdəlik) daxilolma → akt; sonra: debitor borcu (aktiv) akt → daxilolma'), [], 'B'),
            'C1_C' => $c('onec', __('1C: bankdakı avro'), 'azn', __('Aktiv: avronun alındığı gün → satıcıya ödəniş günü (ödənilən məbləğ üzrə; komissiya daxil deyil)'), [], 'C'),
            'C1_D' => $c('onec', __('1C: satıcı ilə hesablaşma'), 'azn', __('Ödəniş aktdan əvvəl: verilmiş avans (aktiv) ödəniş → akt; sonra: kreditor borcu (öhdəlik) akt → ödəniş'), [], 'D'),
            'C1_E' => $c('onec', __('1C: logistika'), 'azn', __('İnvoys ödənişdən əvvəl: kreditor borcu (öhdəlik) invoys → ödəniş; əks halda verilmiş avans (aktiv) ödəniş → invoys'), [], 'E'),
            'C1_M214' => $c('onec', __('1C: seçilmiş ay — 214'), 'azn', __('Seçilmiş ayda tarixlənən müsbət yazılışların cəmi'), [], null),
            'C1_M219' => $c('onec', __('1C: seçilmiş ay — 219.3'), 'azn', __('Seçilmiş ayda tarixlənən mənfi yazılışların mütləq cəmi'), [], null),
            'C1_P' => $c('onec', __('1C: 214 — müsbət fərq'), 'azn', __('Dövrün (1 yanvar → ay sonu) müsbət yazılışlarının cəmi, əvəzləşdirmədən'), [], '214',
                __('Xarici valyutaların manata nisbətən müsbət məzənnə fərqi.')),
            'C1_N' => $c('onec', __('1C: 219.3 — mənfi fərq'), 'azn', __('Dövrün (1 yanvar → ay sonu) mənfi yazılışlarının mütləq cəmi, əvəzləşdirmədən'), [], '219.3',
                __('Xarici valyutaların manata nisbətən məzənnəsinin dəyişməsindən yaranan mənfi fərq.')),
            'C1' => $c('onec', __('1C: xalis'), 'azn', '214 − 219.3', ['C1_P', 'C1_N'], null),
            'C1_FX' => $c('onec', __('Valyuta alqı-satqısı fərqi'), 'azn', __('Rubl satışı: məbləğ × (bank − AMB); avro alışı: məbləğ × (AMB − bank)'), [], null,
                __('Bank kursu ilə AMB kursu arasındakı fərq — 214 / 219.3-ə daxil deyil, ayrıca göstərilir.')),
            'C1_W' => $c('onec', __('1C: xəbərdarlıq'), 'text', null, [], null, __('Konvertasiya günü bankın avro kursu AMB-dən aşağıdırsa və ya rubl kursu AMB-dən 2%-dən çox aşağıdırsa.')),
        ];
    }

    /** Rate column => the day it is taken at, for the formula window (the row's own days are in `_rd`). */
    public static function rateDays(): array
    {
        return [
            'L' => __('hesab verilən gün (K)'), 'M' => __('hesab verilən gün (K)'),
            'U' => __('daxilolma günü (T)'), 'V' => __('daxilolma günü (T)'),
            'Y' => __('satıcıya ödəniş günü (X)'), 'Z' => __('satıcıya ödəniş günü (X)'),
            'AF' => __('valyuta alışı günü'), 'AG' => __('valyuta satışı günü'),
            'AZ' => __('logistika ödənişi günü (AY)'), 'BA' => __('logistika ödənişi günü (AY)'),
            'BJ' => __('akt günü (BI)'), 'BK' => __('akt günü (BI)'),
        ];
    }

    /** AZN per 1 unit at CBAR of the day (never a future bulletin); null when unknown. */
    private function rate(?string $cur, $date): ?float
    {
        if (! $cur || ! $date) {
            return null;
        }
        if ($cur === 'AZN') {
            return 1.0;
        }
        $day = $this->rates->normalize($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10));
        if ($day->gt($this->rates->today())) {
            return null;
        }

        return $this->memo[$cur.$day->format('Ymd')] ??= $this->rates->tryRate($cur, $day);
    }

    /**
     * @param  ?string  $asOf  a month end (Y-m-d): a Trade without an act by then is valued as of that day — the day stands
     *                         in for the act date, and payments after it have not happened yet
     * @return list<array> rows for the given Trades, oldest seller invoice first
     */
    public function rows(Collection $deals, ?string $asOf = null, ?string $oneC = null): array
    {
        $this->asOf = $asOf;
        $this->oneC = $oneC;
        $deals->loadMissing(['invoices.adjustments', 'salesDocuments', 'supplierPayments', 'payments', 'logisticsActs.payments', 'currencyExchanges']);
        $rows = [];
        foreach ($deals as $deal) {
            $invoices = $deal->invoices->where('type', 'supplier')->where('status', '!=', 'cancelled')->sortBy([['invoice_date', 'asc'], ['id', 'asc']])->values();
            $weights = $invoices->mapWithKeys(fn (Invoice $i) => [$i->id => (float) ($i->total_azn ?: $i->total)]);
            $sum = $weights->sum() ?: 1;
            foreach ($invoices as $inv) {
                $rows[] = $this->row($deal, $inv, $weights[$inv->id] / $sum);
            }
        }
        usort($rows, fn ($a, $b) => [$a['_sort'], $a['_id']] <=> [$b['_sort'], $b['_id']]);
        foreach ($rows as $i => &$r) {
            $r['no'] = $i + 1;
        }

        return $rows;
    }

    private function row(Deal $deal, Invoice $inv, float $k): array
    {
        $cur = $inv->currency;
        $sale = $deal->salesDocuments->where('source_invoice_id', $inv->id)->firstWhere('kind', 'commercial')
            ?? $deal->salesDocuments->where('source_invoice_id', $inv->id)->firstWhere('kind', 'proforma');
        $saleCur = $sale?->currency ?? ($inv->isManual() ? $inv->saleCurrency() : $deal->saleCurrency());
        $mul = fn (...$v) => in_array(null, $v, true) ? null : array_product($v);
        $sub = fn ($a, ...$b) => $a === null || in_array(null, $b, true) ? null : $a - array_sum($b);

        $r = ['_sort' => $inv->invoice_date?->format('Ymd'), '_id' => $inv->id, 'deal' => $deal, 'invoice' => $inv, 'sale' => $sale,
            'cur' => $cur, 'saleCur' => $saleCur, 'logCur' => $inv->logistics_currency, 'axCur' => null];

        // documents
        $r['seller_no'] = $inv->number;
        $r['seller_date'] = $inv->invoice_date;
        $r['D'] = $D = $inv->adjustedTotal();
        $sp = $deal->supplierPayments;
        $r['pay_date'] = $sp->max('payment_date');
        $r['buyer_no'] = $sale?->number ?? ($inv->isManual() ? ($inv->sale_number ?: $inv->number) : null);
        $r['buyer_date'] = $sale?->doc_date ?? ($inv->isManual() ? ($inv->sale_date ?? $inv->invoice_date) : null);
        $r['H'] = $H = $sale ? $sale->grandTotal() : $inv->saleTotal();
        $r['I'] = $inv->logistics_amount !== null ? (float) $inv->logistics_amount : null;
        $r['J'] = $J = $sp->isNotEmpty() ? round((float) $sp->sum('fee_amount') * $k, 2) : (\App\Models\SupplierPayment::feeFor($cur, $D)['amount'] ?? null);

        // forecast at the date our invoice is issued
        $r['K'] = $inv->fx_date;
        $r['L'] = $this->rate($cur, $inv->fx_date);
        $r['M'] = $this->rate($saleCur, $inv->fx_date);
        $r['N'] = $N = $inv->fx_base_azn ? (float) $inv->fx_base_azn : null;
        $r['O'] = $O = $inv->fx_target_azn ? (float) $inv->fx_target_azn : null;
        $r['P'] = $P = $mul($D, $N);
        $r['Q'] = $Q = $mul($H, $O);
        $logRate = match (true) { $r['I'] === null => null, $inv->logistics_currency === $cur => $N, $inv->logistics_currency === $saleCur => $O, default => $this->rate($inv->logistics_currency, $inv->fx_date) };
        $r['R'] = $R = $r['I'] === null ? 0.0 : $mul($r['I'], $logRate);
        $r['S'] = $S = $mul($J, $N);
        $r['FP'] = $sub($Q, $P, $R, $S);

        // money in from the buyer
        $in = $deal->payments->where('currency', $saleCur);
        $inSum = (float) $in->sum('amount');
        $r['T'] = $T = $in->max('transaction_date');
        $r['U'] = $this->rate($cur, $T);
        $r['V'] = $V = $inSum > 0 ? $in->sum(fn ($t) => (float) $t->amount * (float) $t->cbar_rate) / $inSum : null;
        $r['W'] = $W = $mul($H, $V);

        // operation: payment to the seller
        $paid = (float) $sp->sum('amount');
        $r['X'] = $X = $r['pay_date'];
        $r['Y'] = $Y = $paid > 0 ? $sp->sum(fn ($p) => (float) $p->amount * (float) $p->cbar_rate) / $paid : null;
        $r['Z'] = $Z = $this->rate($saleCur, $X);
        $r['AA'] = $AA = $mul($D, $Y);
        $r['AC'] = $AC = $mul($H, $Z);

        // bank rates: roubles sold and euros bought for this Trade («Valyuta alış-satışı»)
        $sold = $deal->currencyExchanges->where('direction', 'sell')->where('currency', $saleCur)->where('counter_currency', 'AZN');
        $bought = $deal->currencyExchanges->where('direction', 'buy')->where('currency', $cur)->where('counter_currency', 'AZN');
        $soldSum = (float) $sold->sum('amount');
        $boughtSum = (float) $bought->sum('amount');
        $r['AO'] = $AO = $soldSum > 0 ? round($soldSum * $k, 2) : null;
        $r['AG'] = $AG = $soldSum > 0 ? $sold->sum(fn (CurrencyExchange $x) => (float) $x->amount * (float) $x->bank_rate) / $soldSum : null;
        $r['AP'] = $AP = $boughtSum > 0 ? round($boughtSum * $k, 2) : null;
        $r['AF'] = $AF = $boughtSum > 0 ? $bought->sum(fn (CurrencyExchange $x) => (float) $x->amount * (float) $x->bank_rate) / $boughtSum : null;
        $r['AD'] = $mul($AO, $Z);
        $r['AE'] = $sub($W, $AA);
        $r['AH'] = $AH = $mul($AF, $D);
        $r['AI'] = $AI = $mul($AG, $H);
        $r['AJ'] = $AJ = $mul($J, $Y);
        $r['AK'] = $mul($J, $AF);
        $r['AL'] = $AL = $AO === null ? 0.0 : ($Z === null || $AG === null ? null : $AO * ($Z - $AG));
        $r['AM'] = $AM = $AP === null ? 0.0 : ($AF === null || $Y === null ? null : $AP * ($AF - $Y));
        $r['AR'] = $sub($AI, $Q);
        $r['AS'] = $sub($P, $AH);

        // logistics: payments of this invoice's acts (+ its share of acts not tied to an invoice)
        $acts = $deal->logisticsActs->filter(fn (LogisticsAct $a) => $a->invoice_id === $inv->id || $a->invoice_id === null);
        $part = fn (LogisticsAct $a) => $a->invoice_id === null ? $k : 1.0;
        $lps = $acts->flatMap(fn (LogisticsAct $a) => $a->payments->map(fn ($p) => ['p' => $p, 'a' => $a, 'k' => $part($a)]));
        if ($lps->isNotEmpty()) {
            $r['axCur'] = $lps->groupBy(fn ($x) => $x['p']->currency)->sortByDesc(fn ($g) => $g->sum(fn ($x) => (float) $x['p']->amount))->keys()->first();
            $mine = $lps->filter(fn ($x) => $x['p']->currency === $r['axCur']);
            $aznOf = function ($x) {   // AZN that really left: at the bank's rate of the payment
                $p = $x['p'];
                $per = match (true) { $p->currency === 'AZN' => 1.0, $p->currency === $x['a']->currency => (float) $p->cbar_act_rate, default => (float) $p->cbar_act_rate / max(1e-12, (float) $p->bank_rate) };

                return (float) $p->amount * $per * $x['k'];
            };
            $r['AX'] = $AX = round($mine->sum(fn ($x) => (float) $x['p']->amount * $x['k']), 2);
            $r['AY'] = $AY = $lps->max(fn ($x) => $x['p']->payment_date->format('Y-m-d'));
            $r['AZ'] = $AZ = $this->rate($cur, $AY);
            $r['BC'] = $BC = $lps->sum($aznOf);
            $r['BA'] = $AX > 0 ? $mine->sum($aznOf) / $AX : null;
            $r['BB'] = $BB = $lps->sum(fn ($x) => (float) $x['p']->fee_azn * $x['k']);
            $r['BD'] = $AZ ? $BC / $AZ : null;
        } else {
            $r['AX'] = $r['AY'] = $r['AZ'] = $r['BA'] = $r['BD'] = null;
            $BC = $r['BC'] = $acts->isEmpty() && ! $inv->logistics_amount ? 0.0 : null;
            $BB = $r['BB'] = 0.0;
        }

        $r['AQ'] = $sub($AC, $AA, $BC, $AJ, $BB);
        $r['BE'] = $sub($AC, $AA, $AL, $AM, $AJ, $BC, $BB);

        // at the act date
        $act = $acts->sortByDesc(fn (LogisticsAct $a) => $a->docDate()?->format('Y-m-d'))->first();
        $r['BF'] = $act?->logistics_invoice_number;
        $r['BG'] = $act?->logistics_invoice_date;
        $r['BH'] = $act?->act_number;
        $r['BI'] = $BI = $act ? ($act->act_date ?? $act->docDate()) : null;
        $actReal = $BI;   // the 1C method works with the real act, never a month end standing in for it
        $r['provisional'] = false;
        if ($this->asOf && (! $act?->act_date || $act->act_date->format('Y-m-d') > $this->asOf)) {
            $r['BI'] = $BI = \Carbon\Carbon::parse($this->asOf);   // not finished by the month end: valued as of it
            $r['provisional'] = true;
        }
        $r['BJ'] = $BJ = $this->rate($cur, $BI);
        $r['BK'] = $BK = $this->rate($saleCur, $BI);
        $r['BL'] = $BL = $mul($BJ, $D);
        $r['BM'] = $BM = $mul($BK, $H);
        $r['BN'] = $BN = $sub($BM, $BL);
        $r['BO'] = $BO = $BI === null ? null : $lps->sum(fn ($x) => (float) $x['p']->amount * ($this->rate($x['p']->currency, $BI) ?? 0) * $x['k']);
        $r['BP'] = $BP = $sub($BN, $BO);
        $r['BQ'] = $BQ = $sub($AC, $BM);
        $r['BR'] = $BR = $sub($BL, $AA);
        $r['BS'] = $BS = $sub($BC, $BO);
        $r['BT'] = $BP === null || $BQ === null || $BR === null || $BS === null || $AJ === null ? null : $BP + $BQ + $BR - $BS - $AJ - $BB;

        // exchange differences by the «Məzənnə fərqi» module (Tax Code): the act date is when goods / services pass
        $this->taxDifferences($r, $cur, $saleCur, $lps);
        if ($this->oneC) {
            $this->oneC($r, $cur, $saleCur, $actReal, $in, $sp, $sold, $bought, $lps, $k);
        }

        // the day(s) each rate is taken at — several when it is an amount-weighted average
        $days = fn ($ds) => collect($ds)->filter()->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10))->unique()->sort()->values()->all();
        $r['_rd'] = [
            'L' => $days([$r['K']]), 'M' => $days([$r['K']]),
            'U' => $days([$T]), 'V' => $days($in->pluck('transaction_date')),
            'Y' => $days($sp->pluck('payment_date')), 'Z' => $days([$X]),
            'AF' => $days($bought->pluck('exchange_date')), 'AG' => $days($sold->pluck('exchange_date')),
            'AZ' => $days([$r['AY']]), 'BA' => $days($lps->map(fn ($x) => $x['p']->payment_date)),
            'BJ' => $days([$BI]), 'BK' => $days([$BI]),
        ];

        return $r;
    }

    /**
     * Seller: paid before the act — advance paid, after — payable. Buyer: money before the act — advance
     * received, after — receivable. Logistics: each payment against its own act. FxDifference does the rest
     * (sign, 31.12 revaluation of debts and — as the module's default — of advances).
     */
    private function taxDifferences(array &$r, string $cur, string $saleCur, Collection $lps): void
    {
        $pos = $neg = 0.0;
        $known = false;
        $day = fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10);
        $calc = function (string $case, string $c, float $amount, $d1, $r1, $d2, $r2) use (&$pos, &$neg, &$known, $day): ?float {
            if (! $d1 || ! $d2 || ! $r1 || ! $r2 || abs($amount) < 0.005) {
                return null;
            }
            [$d1, $d2] = [$day($d1), $day($d2)];
            $yearEnd = [];
            for ($y = (int) substr($d1, 0, 4); $y < (int) substr($d2, 0, 4); $y++) {
                if (($ye = $this->rate($c, "{$y}-12-31")) !== null) {
                    $yearEnd[$y] = $ye;
                }
            }
            $res = \App\Support\FxDifference::calc($case, $amount, $d1, (float) $r1, $d2, (float) $r2, $yearEnd, false, true);
            $pos += $res['positive'];
            $neg += $res['negative'];
            $known = true;

            return $res['net'];
        };
        // the earlier event first: an advance is paid / received before the act, a debt is settled after it
        $asOf = $this->asOf;
        $pair = function (string $advance, string $debt, string $c, ?float $amount, $pay, $payRate, $act, $actRate, string $caseKey, string $valueKey) use (&$r, $calc, $day, $asOf) {
            $r[$caseKey] = $r[$valueKey] = null;
            if (! $pay || ! $act || ! $payRate || ! $actRate || ! $amount) {
                return;
            }
            if ($asOf && $day($pay) > $asOf) {
                // not paid by the month end: a debt from the act is revalued to it; before the act there is nothing yet
                if ($r['provisional'] || $day($act) > $asOf || ! ($endRate = $this->rate($c, $asOf))) {
                    return;
                }
                [$pay, $payRate] = [$asOf, $endRate];
            }
            $case = $day($pay) <= $day($act) ? $advance : $debt;
            $r[$caseKey] = \Illuminate\Support\Str::before(\App\Support\FxDifference::labels()[$case], ' —');
            $r[$valueKey] = $case === $advance ? $calc($case, $c, $amount, $pay, $payRate, $act, $actRate) : $calc($case, $c, $amount, $act, $actRate, $pay, $payRate);
        };
        $pair('verilmis_avans', 'alis_borc', $cur, $r['D'], $r['X'], $r['Y'], $r['BI'], $r['BJ'], 'TX_SC', 'TX_S');
        $pair('alinmis_avans', 'satis_borc', $saleCur, $r['H'], $r['T'], $r['V'], $r['BI'], $r['BK'], 'TX_BC', 'TX_B');

        $r['TX_L'] = null;
        foreach ($lps as $x) {   // logistics: each payment against its own act, in the act's currency
            $a = $x['a'];
            $actDay = $a->act_date ?? $a->docDate();
            if ($this->asOf) {
                if ($day($x['p']->payment_date) > $this->asOf) {
                    continue;   // paid after the month end
                }
                if (! $a->act_date || $day($a->act_date) > $this->asOf) {
                    $actDay = $this->asOf;   // service not received by the month end: the advance is valued at it
                }
            }
            $actRate = $actDay ? $this->rate($a->currency, $actDay) : null;
            $payRate = (float) $x['p']->cbar_act_rate;
            if (! $actDay || ! $actRate || ! $payRate) {
                continue;
            }
            $amount = (float) $x['p']->act_amount * $x['k'];
            $net = $day($x['p']->payment_date) <= $day($actDay)
                ? $calc('verilmis_avans', $a->currency, $amount, $x['p']->payment_date, $payRate, $actDay, $actRate)
                : $calc('alis_borc', $a->currency, $amount, $actDay, $actRate, $x['p']->payment_date, $payRate);
            if ($net !== null) {
                $r['TX_L'] = ($r['TX_L'] ?? 0) + $net;
            }
        }
        $r['TX_P'] = $known ? round($pos, 2) : null;
        $r['TX_N'] = $known ? round($neg, 2) : null;
        $r['TX'] = $known ? round($pos - $neg, 2) : null;
    }

    /**
     * The postings of one currency balance (1C): points = the day it arises, every month end in between, the day it
     * closes (or, still open, the period's month end); each consecutive pair is a posting
     * asset ROUND(amount × (new − old), 2), liability ROUND(amount × (old − new), 2), dated on the new point.
     *
     * @return list<array{item: string, date: string, from: string, old: float, new: float, amount: float, cur: string, diff: float}>|null null when a rate is missing
     */
    private function postings(string $item, float $amount, string $cur, string $start, ?string $end, bool $asset): ?array
    {
        $last = $this->oneC;
        if ($amount < 0.005 || $start > $last) {
            return [];
        }
        $close = $end && $end <= $last ? $end : $last;
        if ($close <= $start) {
            return [];   // same day: no posting
        }
        $points = [$start];
        for ($m = \Carbon\Carbon::parse($start)->endOfMonth(); $m->toDateString() < $close; $m = $m->addDay()->endOfMonth()) {
            if ($m->toDateString() > $start) {
                $points[] = $m->toDateString();
            }
        }
        $points[] = $close;
        $out = [];
        for ($i = 1; $i < count($points); $i++) {
            $old = $this->rate($cur, $points[$i - 1]);
            $new = $this->rate($cur, $points[$i]);
            if ($old === null || $new === null) {
                return null;
            }
            $diff = round($amount * ($new - $old) * ($asset ? 1 : -1), 2);
            if (abs($diff) >= 0.005) {
                $out[] = ['item' => $item, 'date' => $points[$i], 'from' => $points[$i - 1], 'old' => $old, 'new' => $new, 'amount' => $amount, 'cur' => $cur, 'diff' => $diff];
            }
        }

        return $out;
    }

    /**
     * Lots in, outflows consuming them first in, first out: [[amount, from, to]] — what was held from when to when.
     * What is never spent stays open only when it is more than a small remainder (2 % of what came in).
     */
    private static function fifo(array $ins, array $outs): array
    {
        usort($ins, fn ($a, $b) => strcmp($a[1], $b[1]));
        usort($outs, fn ($a, $b) => strcmp($a[1], $b[1]));
        $held = [];
        $total = array_sum(array_column($ins, 0));
        foreach ($outs as [$amount, $day]) {
            while ($amount > 0.005 && $ins) {
                $take = min($amount, $ins[0][0]);
                $held[] = [$take, $ins[0][1], $day];
                $ins[0][0] -= $take;
                $amount -= $take;
                if ($ins[0][0] < 0.005) {
                    array_shift($ins);
                }
            }
        }
        foreach ($ins as [$left, $day]) {
            if ($left > 0.02 * $total) {
                $held[] = [$left, $day, null];   // still on the account
            }
        }

        return $held;
    }

    private function oneC(array &$r, string $cur, string $saleCur, $act, Collection $in, Collection $sp, Collection $sold, Collection $bought, Collection $lps, float $k): void
    {
        $day = fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : ($d ? substr((string) $d, 0, 10) : null);
        $act = $day($act);
        $entries = [];
        $cases = [];
        $warn = [];
        $missing = false;
        $add = function (string $col, string $item, float $amount, string $c, string $start, ?string $end, bool $asset) use (&$entries, &$missing) {
            $p = $this->postings($item, $amount, $c, $start, $end, $asset);
            if ($p === null) {
                $missing = true;

                return;
            }
            foreach ($p as $e) {
                $entries[] = $e + ['col' => $col];
            }
        };

        // A) roubles on the bank: from coming in until sold, each sale on its own. What stays after the conversion
        //    (and pays the carrier later) is left out — as in the company's verified 1C figures (Trades 916162025, 916162331)
        $rubIns = $in->map(fn ($t) => [(float) $t->amount * $k, $day($t->transaction_date)])->values()->all();
        $rubOuts = $sold->map(fn ($x) => [(float) $x->amount * $k, $day($x->exchange_date)])->values()->all();
        foreach (array_filter(self::fifo($rubIns, $rubOuts), fn ($h) => $h[2] !== null) as [$amount, $from, $to]) {
            $add('C1_A', __('Bankdakı rubl'), $amount, $saleCur, $from, $to, true);
        }

        // B) the buyer: money before the act — an advance received (liability) until the act; after — a receivable (asset)
        $received = 0.0;
        foreach ($in as $t) {
            $amount = (float) $t->amount * $k;
            $received += $amount;
            $paid = $day($t->transaction_date);
            if (! $act || $paid <= $act) {
                $cases['B'] = __('alıcı: alınmış avans');
                $add('C1_B', __('Alınmış avans'), $amount, $saleCur, $paid, $act, false);
            } else {
                $cases['B'] = __('alıcı: debitor borcu');
                $add('C1_B', __('Debitor borcu'), $amount, $saleCur, $act, $paid, true);
            }
        }
        if ($act && $r['H'] && $r['H'] - $received > 0.005) {   // billed, not paid yet
            $cases['B'] ??= __('alıcı: debitor borcu');
            $add('C1_B', __('Debitor borcu'), $r['H'] - $received, $saleCur, $act, null, true);
        }

        // C) euros on the bank: from bought until paid to the seller (the payment; its fee is left out, as in the
        //    company's verified 1C figures — Trade 916161697 matches only so)
        $eurIns = $bought->map(fn ($x) => [(float) $x->amount * $k, $day($x->exchange_date)])->values()->all();
        $eurOuts = $sp->map(fn ($p) => [(float) $p->amount * $k, $day($p->payment_date)])->values()->all();
        foreach (self::fifo($eurIns, $eurOuts) as [$amount, $from, $to]) {
            $add('C1_C', __('Bankdakı avro'), $amount, $cur, $from, $to, true);
        }

        // D) the seller: paid before the act — an advance given (asset) until the act; after — a payable (liability)
        $paidSeller = 0.0;
        foreach ($sp as $p) {
            $amount = (float) $p->amount * $k;
            $paidSeller += $amount;
            $paid = $day($p->payment_date);
            if (! $act || $paid <= $act) {
                $cases['D'] = __('satıcı: verilmiş avans');
                $add('C1_D', __('Verilmiş avans'), $amount, $cur, $paid, $act, true);
            } else {
                $cases['D'] = __('satıcı: kreditor borcu');
                $add('C1_D', __('Kreditor borcu'), $amount, $cur, $act, $paid, false);
            }
        }
        if ($act && $r['D'] - $paidSeller > 0.005) {
            $cases['D'] ??= __('satıcı: kreditor borcu');
            $add('C1_D', __('Kreditor borcu'), $r['D'] - $paidSeller, $cur, $act, null, false);
        }

        // E) logistics: the invoice before the payment — a payable; the payment first — an advance given
        foreach ($lps as $x) {
            $a = $x['a'];
            $inv = $day($a->logistics_invoice_date ?? $a->act_date);
            $paid = $day($x['p']->payment_date);
            $amount = (float) $x['p']->act_amount * $x['k'];
            if ($inv && $inv <= $paid) {
                $cases['E'] = __('logistika: kreditor borcu');
                $add('C1_E', __('Logistika borcu'), $amount, $a->currency, $inv, $paid, false);
            } else {
                $cases['E'] = __('logistika: verilmiş avans');
                $add('C1_E', __('Logistikaya avans'), $amount, $a->currency, $paid, $inv, true);
            }
        }

        // conversion: the bank against CBAR — not part of 214 / 219.3, shown on its own; a suspicious day is flagged
        $fx = 0.0;
        foreach ($sold as $x) {
            $cbar = $this->rate($saleCur, $x->exchange_date);
            if ($cbar) {
                $fx += (float) $x->amount * $k * ((float) $x->bank_rate - $cbar);
                if ((float) $x->bank_rate < $cbar * 0.98) {
                    $warn[] = __('Rubl :v1 tarixində AMB-dən 2%-dən çox aşağı satılıb — konvertasiya tarixi səhv ola bilər, bank çıxarışı ilə yoxlayın.', ['v1' => azdate($x->exchange_date)]);
                }
            }
        }
        foreach ($bought as $x) {
            $cbar = $this->rate($cur, $x->exchange_date);
            if ($cbar) {
                $fx += (float) $x->amount * $k * ($cbar - (float) $x->bank_rate);
                if ((float) $x->bank_rate < $cbar) {
                    $warn[] = __('Avro :v1 tarixində AMB-dən ucuz alınıb — konvertasiya tarixi səhv ola bilər, bank çıxarışı ilə yoxlayın.', ['v1' => azdate($x->exchange_date)]);
                }
            }
        }
        if ($missing) {
            $warn[] = __('Bəzi tarixlər üçün AMB məzənnəsi yoxdur — həmin yazılışlar hesablanmadı.');
        }

        // the period: 1 January of the year → the month end (1C's running total); the month on its own too
        $from = substr($this->oneC, 0, 4).'-01-01';
        $monthFrom = substr($this->oneC, 0, 7).'-01';
        $in = array_values(array_filter($entries, fn ($e) => $e['date'] >= $from && $e['date'] <= $this->oneC));
        $sum = fn (array $es, string $col) => ($v = array_filter($es, fn ($e) => $e['col'] === $col)) ? round(array_sum(array_column($v, 'diff')), 2) : null;
        foreach (['C1_A', 'C1_B', 'C1_C', 'C1_D', 'C1_E'] as $col) {
            $r[$col] = $sum($in, $col);
        }
        $pos = fn (array $es) => round(array_sum(array_filter(array_column($es, 'diff'), fn ($d) => $d > 0)), 2);
        $neg = fn (array $es) => round(-array_sum(array_filter(array_column($es, 'diff'), fn ($d) => $d < 0)), 2);
        $month = array_filter($in, fn ($e) => $e['date'] >= $monthFrom);
        $r['C1_P'] = $pos($in);
        $r['C1_N'] = $neg($in);
        $r['C1'] = round($r['C1_P'] - $r['C1_N'], 2);
        $r['C1_M214'] = $pos($month);
        $r['C1_M219'] = $neg($month);
        $r['C1_FX'] = ($sold->isNotEmpty() || $bought->isNotEmpty()) ? round($fx, 2) : null;
        $r['C1_H'] = $cases ? implode(' · ', $cases) : null;
        $r['C1_W'] = $warn ? implode(' ', array_unique($warn)) : null;
        $r['_c1'] = $in;   // the postings, for the formula window
    }

    /** Sums of the AZN columns over rows that have them. */
    public static function totals(array $rows): array
    {
        $out = [];
        foreach (self::columns() as $key => $c) {
            if ($c['t'] === 'azn') {
                $vals = array_filter(array_column($rows, $key), fn ($v) => $v !== null);
                $out[$key] = $vals ? array_sum($vals) : null;
            }
        }

        return $out;
    }
}
