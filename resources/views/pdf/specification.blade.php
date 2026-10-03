{{-- Спецификация к Контракту (RU), laid out like the company's SP sheet. Rendered from the document's current state. --}}
@php
    $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',');
    $total = $doc->grandTotal();
@endphp
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Спецификация № {{ $doc->number }}</title>
<style>
    @page { margin: 50px 50px 46px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 10px; color: #111; }
    table { border-collapse: collapse; width: 100%; }
    .head { margin-left: 34%; width: 66%; font-size: 10.5px; }
    .head td { padding: 2px 4px; font-weight: bold; }
    .items { margin-top: 34px; }
    .items th { border: 1px solid #222; padding: 8px 5px; font-weight: bold; font-size: 9.5px; text-align: center; background: #f2f2f2; }
    .items td { border: 1px solid #222; padding: 6px 5px; font-size: 9.5px; vertical-align: middle; }
    .items td.c { text-align: center; }
    .items td.r { text-align: right; white-space: nowrap; }
    .items tr.sum td { font-weight: bold; }
    .words { margin-top: 26px; }
    .words td { padding: 3px 0; vertical-align: top; }
    .words td.k { width: 22%; font-weight: bold; }
    .sign { margin-top: 44px; }
    .sign td { width: 50%; vertical-align: top; padding-right: 30px; line-height: 1.6; }
    .muted { color: #555; font-size: 8.5px; }
</style>
</head>
<body>
<table class="head">
    <tr><td>Спецификация №</td><td>{{ $doc->number }}</td><td>от</td><td>{{ $doc->doc_date->format('d.m.Y') }}</td></tr>
    @if($doc->contract_number)
        <tr><td>к Контракту №</td><td>{{ $doc->contract_number }}</td><td>от</td><td>{{ $doc->contract_date }}</td></tr>
    @endif
</table>

<table class="items">
    <thead>
    <tr>
        <th style="width: 7%;">Поз№.</th><th>Наименование товара</th><th style="width: 10%;">Един. измер.</th>
        <th style="width: 12%;">Количество</th><th style="width: 15%;">Цена за ед., рубли</th><th style="width: 18%;">Общая сумма, рубли</th>
    </tr>
    </thead>
    <tbody>
    @foreach($doc->lines as $l)
        <tr>
            <td class="c">{{ $l['n'] }}</td><td>{{ $l['description'] }}</td><td class="c">{{ $l['uom'] ?? '' }}</td>
            <td class="r">{{ $qty($l['quantity']) }}</td><td class="r">{{ $fmt($l['unit_price']) }}</td><td class="r">{{ $fmt($l['total']) }}</td>
        </tr>
    @endforeach
    @if((float) $doc->freight)<tr><td colspan="5">Фрахт</td><td class="r">{{ $fmt($doc->freight) }}</td></tr>@endif
    @if((float) $doc->insurance)<tr><td colspan="5">Страхование</td><td class="r">{{ $fmt($doc->insurance) }}</td></tr>@endif
    <tr class="sum"><td colspan="5">ИТОГО:</td><td class="r">{{ $fmt($total) }}</td></tr>
    </tbody>
</table>

<table class="words">
    <tr><td class="k">Итого:</td><td>{{ \App\Support\RuMoney::words($total, $doc->currency) }}</td></tr>
    @if($doc->payment_terms)<tr><td class="k" style="padding-top: 12px;">Условия оплаты:</td><td style="padding-top: 12px;">{{ $doc->payment_terms }}</td></tr>@endif
    @if($doc->delivery_terms)<tr><td class="k" style="padding-top: 12px;">Срок поставки:</td><td style="padding-top: 12px;">{{ $doc->delivery_terms }}</td></tr>@endif
    @if($doc->notes)<tr><td class="k" style="padding-top: 12px;">Примечание:</td><td style="padding-top: 12px;">{!! nl2br(e($doc->notes)) !!}</td></tr>@endif
</table>

<table class="sign">
    <tr>
        <td><b>От ПРОДАВЦА</b><br><br><br>_________________<br><span class="muted">подпись</span><br>{{ $doc->seller_signatory }}</td>
        <td><b>От ПОКУПАТЕЛЯ</b><br><br><br>_________________<br><span class="muted">подпись</span><br>{{ $doc->buyer_signatory }}</td>
    </tr>
</table>
</body>
</html>
