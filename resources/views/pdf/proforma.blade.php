{{-- Proforma Invoice (EN), laid out like the company's own PROFORMA sheet. Rendered from the document's current state. --}}
@php
    $fmt = fn ($v) => number_format((float) $v, 2, '.', ',');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $price = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $cur = $doc->currency;
    $block = fn ($text) => collect(preg_split('/\R/', (string) $text))->map(fn ($l) => e($l))
        ->map(fn ($l) => filter_var(trim(html_entity_decode($l)), FILTER_VALIDATE_EMAIL) ? '<span class="mail">'.$l.'</span>' : $l)->implode('<br>');
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Proforma Invoice {{ $doc->number }}</title>
<style>
    @page { margin: 34px 40px 40px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 9.5px; color: #111; }
    table { border-collapse: collapse; width: 100%; }
    .top td { vertical-align: top; }
    .brand { font-size: 21px; font-weight: bold; color: #4a4a4a; letter-spacing: .3px; }
    .doc-title { font-size: 21px; font-weight: bold; color: #4a4a4a; text-align: right; }
    .logo { margin-top: 8px; max-height: 78px; max-width: 160px; }
    .meta { margin-top: 14px; margin-left: auto; width: auto; }
    .meta td { padding: 1px 0 1px 10px; font-size: 8.5px; font-weight: bold; font-style: italic; text-align: right; white-space: nowrap; }
    .bar td { background: #4fa58f; color: #fff; font-weight: bold; padding: 3px 8px; font-size: 9px; border: 1px solid #3d8b77; }
    .parties td { vertical-align: top; padding: 2px 8px 0 18px; line-height: 1.38; font-size: 9.5px; }
    .mail { color: #1f5fa8; text-decoration: underline; }
    .items { margin-top: 18px; }
    .items th { background: #4fa58f; color: #fff; font-weight: bold; font-size: 8.5px; padding: 7px 4px; border: 1px solid #222; }
    .items td { border: 1px solid #222; padding: 0 5px; height: 40px; text-align: center; font-size: 9px; }
    .items td.r { text-align: right; }
    .bottom { margin-top: 20px; }
    .bottom td { vertical-align: top; }
    .terms { border: 1px solid #bdbdbd; }
    .terms .h { background: #4fa58f; color: #fff; font-weight: bold; padding: 3px 18px; }
    .terms .b { padding: 4px 18px 14px; font-weight: bold; line-height: 1.7; }
    .totals td { padding: 2px 4px; font-size: 9.5px; }
    .totals td.v { border: 1px solid #bdbdbd; text-align: right; width: 92px; }
    .sign { margin-top: 56px; border: 1px solid #bdbdbd; }
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
            <div class="doc-title">PROFORMA INVOICE</div>
            <table class="meta" style="width: 100%;">
                <tr><td style="width: 70%;">Proforma Inv. Number:</td><td>{{ $doc->number }}</td></tr>
                <tr><td>Proforma Inv. Date:</td><td>{{ $doc->doc_date->format('d/m/Y') }}</td></tr>
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
        <th style="width: 7%;">Item #</th><th>Description</th><th style="width: 11%;">Custom Code</th><th style="width: 7%;">QTY</th>
        <th style="width: 8%;">UOM</th><th style="width: 11%;">Unit Price {{ $cur }}</th><th style="width: 13%;">Total Price {{ $cur }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach($doc->lines as $l)
        <tr>
            <td>{{ $l['n'] }}</td><td>{{ $l['description'] }}</td><td>{{ $l['hs_code'] ?? '' }}</td><td>{{ $qty($l['quantity']) }}</td>
            <td>{{ $l['uom'] ?? '' }}</td><td>{{ $price($l['unit_price']) }}</td><td class="r">{{ $fmt($l['total']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="bottom">
    <tr>
        <td style="width: 62%;">
            <div class="terms">
                <div class="h">Special Notes, Terms of Sale</div>
                <div class="b">
                    @if($doc->payment_terms)Payment Terms: {{ $doc->payment_terms }}<br>@endif
                    @if($doc->delivery_terms)Delivery&nbsp; Terms: {{ $doc->delivery_terms }}<br>@endif
                    @if($doc->notes)<span style="font-weight: normal;">{!! nl2br(e($doc->notes)) !!}</span>@endif
                </div>
            </div>
        </td>
        <td style="padding-left: 40px;">
            <table class="totals">
                <tr><td>Subtotal ({{ $cur }}):</td><td class="v">{{ $fmt($doc->linesTotal()) }}</td></tr>
                <tr><td>Freight:</td><td class="v">{{ (float) $doc->freight ? $fmt($doc->freight) : '-' }}</td></tr>
                <tr><td>Insurance:</td><td class="v">{{ (float) $doc->insurance ? $fmt($doc->insurance) : '-' }}</td></tr>
                <tr><td>TOTAL ({{ $cur }}):</td><td class="v">{{ $fmt($doc->grandTotal()) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="sign">
    <tr><td style="width: 57%;">Signature</td><td class="date"><div style="text-align: left; margin-bottom: 14px;">Date</div>{{ $doc->doc_date->format('d/m/Y') }}</td></tr>
</table>
</body>
</html>
