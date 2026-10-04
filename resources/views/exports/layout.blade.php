<!doctype html>
<html lang="az">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 22mm 12mm 16mm 12mm; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 9.5px; color: #1d2433; margin: 0; }
    header { position: fixed; top: -16mm; left: 0; right: 0; height: 12mm; border-bottom: 1.5px solid #0f8f7e; }
    header .brand { font-size: 12px; font-weight: bold; color: #0b1122; }
    header .brand span { color: #0f8f7e; }
    header .meta { font-size: 8.5px; color: #667085; text-align: right; }
    footer { position: fixed; bottom: -10mm; left: 0; right: 0; height: 8mm; font-size: 8px; color: #98a2b3; border-top: 1px solid #e4e7ec; padding-top: 2mm; }
    h1 { font-size: 15px; margin: 0 0 4px; color: #0b1122; }
    .sub { color: #667085; font-size: 8.5px; margin-bottom: 10px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #0f8f7e; color: #fff; font-weight: bold; text-align: left; padding: 5px 6px; font-size: 8.5px; }
    table.data td { padding: 4.5px 6px; border-bottom: 1px solid #eaecf0; vertical-align: top; }
    table.data tr:nth-child(even) td { background: #f8f9fb; }
    table.data .num { text-align: right; white-space: nowrap; }
    table.data tfoot td { font-weight: bold; border-top: 1.5px solid #1d2433; background: #fff; }
    .kv { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .kv td { padding: 4px 6px; border-bottom: 1px solid #eaecf0; }
    .kv td.k { color: #667085; width: 32%; }
    .note { margin-top: 8px; font-size: 8px; color: #b42318; }
    .section { font-size: 11px; font-weight: bold; margin: 14px 0 6px; color: #0b1122; }
</style>
</head>
<body>
<header>
    <table width="100%"><tr>
        <td class="brand">{{ $company?->name ?? 'TradeFlow' }} <span>·</span> <span style="color:#667085;font-weight:normal;font-size:9px">{{ $company?->voen ? __('VÖEN ').$company->voen : '' }}</span></td>
        <td class="meta">{{ __('TradeFlow') }}<br>{{ now()->format('d.m.Y H:i') }}</td>
    </tr></table>
</header>
<footer>
    <table width="100%"><tr>
        <td>{{ $title }}</td>
        <td style="text-align:right">{{ __('Çap edən:') }} {{ auth()->user()?->name }}</td>
    </tr></table>
</footer>
<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->getFont('DejaVu Sans');
        $pdf->page_text($pdf->get_width() - 80, $pdf->get_height() - 28, "Səhifə {PAGE_NUM} / {PAGE_COUNT}", $font, 7.5, [0.6, 0.63, 0.7]);
    }
</script>
<main>
    @yield('content')
</main>
</body>
</html>
