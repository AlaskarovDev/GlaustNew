@extends('exports.layout')
@section('content')
    @php $c = $contract; $cp = $c->counterparty; @endphp
    <h1>Müqavilə № {{ $c->number }}</h1>
    <div class="sub">{{ $c->kind === 'sale' ? 'Satış müqaviləsi' : 'Alış müqaviləsi' }} · {{ azdate($c->contract_date) }} · Status: {{ status_label('contract', $c->status) }}</div>

    <table width="100%" style="margin-bottom:12px"><tr>
        <td width="50%" valign="top" style="padding-right:10px">
            <div class="section">{{ $c->kind === 'sale' ? 'Satıcı' : 'Alıcı' }}</div>
            <table class="kv">
                <tr><td class="k">Ad</td><td>{{ $company->name }}</td></tr>
                <tr><td class="k">VÖEN</td><td>{{ $company->voen ?? '—' }}</td></tr>
                <tr><td class="k">Ünvan</td><td>{{ $company->address ?? '—' }}</td></tr>
                <tr><td class="k">Rekvizitlər</td><td>{{ $company->bank_details ?? '—' }}</td></tr>
            </table>
        </td>
        <td width="50%" valign="top" style="padding-left:10px">
            <div class="section">{{ $c->kind === 'sale' ? 'Alıcı (müştəri)' : 'Satıcı (təchizatçı)' }}</div>
            <table class="kv">
                <tr><td class="k">Ad</td><td>{{ $cp->name }}</td></tr>
                <tr><td class="k">VÖEN</td><td>{{ $cp->voen ?? '—' }}</td></tr>
                <tr><td class="k">Ünvan</td><td>{{ collect([$cp->address, $cp->city, $cp->country])->filter()->implode(', ') ?: '—' }}</td></tr>
                <tr><td class="k">IBAN</td><td>{{ $cp->iban ?? '—' }} {{ $cp->bank_name ? '('.$cp->bank_name.')' : '' }}</td></tr>
            </table>
        </td>
    </tr></table>

    <div class="section">Şərtlər</div>
    <table class="kv">
        <tr><td class="k">Mövzu</td><td>{{ $c->subject }}</td></tr>
        <tr><td class="k">Məbləğ</td><td><b>{{ money($c->amount, $c->currency, false) }}</b>
            @if($c->currency !== 'AZN') (≈ {{ money($c->amount_azn, 'AZN', false) }}, CBAR {{ rate_fmt($c->cbar_rate) }} — {{ azdate($c->rate_date) }})@endif</td></tr>
        <tr><td class="k">Müddət</td><td>{{ azdate($c->start_date) }} — {{ azdate($c->end_date) }} {{ $c->auto_renew ? '· avtomatik uzadılır' : '' }}</td></tr>
        <tr><td class="k">Ödəniş şərtləri</td><td>{{ $c->payment_terms ?? '—' }}</td></tr>
        <tr><td class="k">Layihə</td><td>{{ $c->project ? $c->project->code.' · '.$c->project->name : '—' }}</td></tr>
        <tr><td class="k">Məsul şəxs</td><td>{{ $c->responsible?->name ?? '—' }}</td></tr>
    </table>

    @if($c->payments->isNotEmpty())
        <div class="section">Ödəniş qrafiki</div>
        <table class="data">
            <thead><tr><th>#</th><th>Tarix</th><th>Qeyd</th><th class="num">Məbləğ ({{ $c->currency }})</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($c->payments as $i => $p)
                <tr><td>{{ $i + 1 }}</td><td>{{ azdate($p->due_date) }}</td><td>{{ $p->note }}</td><td class="num">{{ num($p->amount) }}</td><td>{{ $p->paid_at ? 'Ödənilib '.azdate($p->paid_at) : 'Gözləmədə' }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><td colspan="3">Cəmi</td><td class="num">{{ num($c->payments->sum('amount')) }}</td><td></td></tr></tfoot>
        </table>
    @endif

    @if($c->notes)
        <div class="section">Qeydlər</div>
        <p>{!! nl2br(e($c->notes)) !!}</p>
    @endif

    <table width="100%" style="margin-top:40px"><tr>
        <td width="50%">______________________<br><span style="color:#667085">{{ $company->name }}</span></td>
        <td width="50%" style="text-align:right">______________________<br><span style="color:#667085">{{ $cp->name }}</span></td>
    </tr></table>
@endsection
