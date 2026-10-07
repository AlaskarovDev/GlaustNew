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

    /** @return list<array> rows for the given Trades, oldest seller invoice first */
    public function rows(Collection $deals): array
    {
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
        $r['buyer_no'] = $sale?->number ?? ($inv->isManual() ? $inv->number : null);
        $r['buyer_date'] = $sale?->doc_date ?? ($inv->isManual() ? $inv->invoice_date : null);
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

        return $r;
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
