{{-- Packing List (EN), laid out like the company's PL sheet: header as the invoice, then pallet by pallet. --}}
@php
    $n = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $block = fn ($text) => collect(preg_split('/\R/', (string) $text))->map(fn ($l) => e($l))
        ->map(fn ($l) => filter_var(trim(html_entity_decode($l)), FILTER_VALIDATE_EMAIL) ? '<span class="mail">'.$l.'</span>' : $l)->implode('<br>');
    $totals = $doc->packingTotals();
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>PACKING LIST {{ $doc->number }}</title>
<style>
    @page { margin: 34px 36px 40px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 9px; color: #111; }
    table { border-collapse: collapse; width: 100%; }
    .top td { vertical-align: top; }
    .brand { font-size: 21px; font-weight: bold; color: #4a4a4a; letter-spacing: .3px; }
    .doc-title { font-size: 21px; font-weight: bold; color: #4a4a4a; text-align: right; }
    .logo { margin-top: 8px; max-height: 78px; max-width: 160px; }
    .meta { margin-top: 14px; }
    .meta td { padding: 1px 0 1px 10px; font-size: 8.5px; font-weight: bold; font-style: italic; text-align: right; white-space: nowrap; }
    .bar td { background: #4fa58f; color: #fff; font-weight: bold; padding: 3px 8px; font-size: 9px; border: 1px solid #3d8b77; }
    .parties td { vertical-align: top; padding: 2px 8px 0 18px; line-height: 1.38; }
    .mail { color: #1f5fa8; text-decoration: underline; }
    .items { margin-top: 16px; }
    .items th { background: #4fa58f; color: #fff; font-weight: bold; font-size: 8px; padding: 6px 3px; border: 1px solid #222; }
    .items td { border: 1px solid #222; padding: 3px 4px; text-align: center; font-size: 8.5px; }
    .items td.l { text-align: left; }
    .items tr.pallet td { background: #eef6f3; font-weight: bold; text-align: left; }
    .items tr.total td { font-weight: bold; background: #f3f3f3; }
    .sign { margin-top: 40px; border: 1px solid #bdbdbd; }
    .sign td { border: 1px solid #bdbdbd; height: 40px; vertical-align: top; padding: 3px; font-size: 8px; }
    .sign .date { text-align: center; vertical-align: bottom; padding-bottom: 4px; font-size: 8.5px; }
</style>
</head>
<body>
<table class="top">
    <tr>
        <td style="width: 52%; text-align: center;">
            <div class="brand">{{ $doc->heading }}</div>
            @if($logo)<img src="{{ $logo }}" class="logo" alt="">@endif
        </td>
        <td>
            <div class="doc-title">PACKING LIST</div>
            <table class="meta">
                <tr><td style="width: 70%;">Inv. Number:</td><td>{{ $doc->number }}</td></tr>
                <tr><td>Inv. Date:</td><td>{{ $doc->doc_date->format('d/m/Y') }}</td></tr>
                @if($doc->contract_number)<tr><td>Contract N:</td><td>{{ $doc->contract_number }}</td></tr>@endif
                @if($doc->contract_date)<tr><td>Cont. Date:</td><td>{{ $doc->contract_date }}</td></tr>@endif
            </table>
        </td>
    </tr>
</table>

<table class="bar" style="margin-top: 16px;"><tr><td style="width: 50%;">SELLER:</td><td>CUSTOMER:</td></tr></table>
<table class="parties"><tr><td style="width: 50%;">{!! $block($doc->seller_block) !!}</td><td>{!! $block($doc->customer_block) !!}</td></tr></table>

<table class="items">
    <thead>
    <tr>
        <th style="width: 5%;">No.</th><th style="width: 12%;">Code</th><th>Description</th><th style="width: 9%;">Package</th>
        <th style="width: 10%;">Quantity</th><th style="width: 11%;">Total</th><th style="width: 10%;">Weight with packing</th><th style="width: 10%;">Weight with pallet</th>
    </tr>
    </thead>
    <tbody>
    @foreach($doc->pallets() as $p)
        @php $items = $p['items'] ?? []; @endphp
        <tr class="pallet"><td colspan="8">{{ $p['title'] }}@if(! empty($p['packing'])) &nbsp;·&nbsp; {{ $p['packing'] }}@endif</td></tr>
        @foreach($items as $j => $it)
            <tr>
                <td>{{ $j + 1 }}</td><td>{{ $it['code'] ?? '' }}</td><td class="l">{{ $it['description'] }}</td><td>{{ $it['package'] ?? '' }}</td>
                <td>{{ $n($it['quantity'] ?? null) }} {{ $it['qty_unit'] ?? '' }}</td><td>{{ $n($it['total'] ?? null) }} {{ $it['total_unit'] ?? '' }}</td>
                <td>{{ $n($it['weight'] ?? null) }}</td>
                @if($j === 0)<td rowspan="{{ count($items) }}" style="vertical-align: middle;">{{ $n($p['weight'] ?? null) }}</td>@endif
            </tr>
        @endforeach
    @endforeach
    <tr class="total">
        <td colspan="6" style="text-align: left;">Total : {{ $totals['pallets'] }} {{ $totals['pallets'] === 1 ? 'Pallet' : 'Pallets' }}</td>
        <td>{{ $n($totals['packed']) }}</td><td>{{ $n($totals['weight']) }}</td>
    </tr>
    </tbody>
</table>
@if($doc->notes)<p style="margin-top: 10px;">{!! nl2br(e($doc->notes)) !!}</p>@endif

<table class="sign">
    <tr><td style="width: 57%;">Signature</td><td class="date"><div style="text-align: left; margin-bottom: 14px;">Date</div>{{ $doc->doc_date->format('d/m/Y') }}</td></tr>
</table>
</body>
</html>
