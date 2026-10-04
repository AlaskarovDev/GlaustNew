@extends('exports.layout')
@section('content')
    @php $c = $contract; $cp = $c->counterparty; @endphp
    <h1>{{ __('Müqavilə №') }} {{ $c->number }}</h1>
    <div class="sub">{{ $c->kind === 'sale' ? __('Satış müqaviləsi') : __('Alış müqaviləsi') }} · {{ azdate($c->contract_date) }} · Status: {{ status_label('contract', $c->status) }}</div>

    <table width="100%" style="margin-bottom:12px"><tr>
        <td width="50%" valign="top" style="padding-right:10px">
            <div class="section">{{ $c->kind === 'sale' ? __('Satıcı') : __('Alıcı') }}</div>
            <table class="kv">
                <tr><td class="k">{{ __('Ad') }}</td><td>{{ $company->name }}</td></tr>
                <tr><td class="k">{{ __('VÖEN') }}</td><td>{{ $company->voen ?? '—' }}</td></tr>
                <tr><td class="k">{{ __('Ünvan') }}</td><td>{{ $company->address ?? '—' }}</td></tr>
                <tr><td class="k">{{ __('Rekvizitlər') }}</td><td>{{ $company->bank_details ?? '—' }}</td></tr>
            </table>
        </td>
        <td width="50%" valign="top" style="padding-left:10px">
            <div class="section">{{ $c->kind === 'sale' ? __('Alıcı (müştəri)') : __('Satıcı (təchizatçı)') }}</div>
            <table class="kv">
                <tr><td class="k">{{ __('Ad') }}</td><td>{{ $cp->name }}</td></tr>
                <tr><td class="k">{{ __('VÖEN') }}</td><td>{{ $cp->voen ?? '—' }}</td></tr>
                <tr><td class="k">{{ __('Ünvan') }}</td><td>{{ collect([$cp->address, $cp->city, $cp->country])->filter()->implode(', ') ?: '—' }}</td></tr>
                <tr><td class="k">IBAN</td><td>{{ $cp->iban ?? '—' }} {{ $cp->bank_name ? '('.$cp->bank_name.')' : '' }}</td></tr>
            </table>
        </td>
    </tr></table>

    <div class="section">{{ __('Şərtlər') }}</div>
    <table class="kv">
        <tr><td class="k">{{ __('Mövzu') }}</td><td>{{ $c->subject }}</td></tr>
        <tr><td class="k">{{ __('Məbləğ') }}</td><td><b>{{ money($c->amount, $c->currency, false) }}</b>
            @if($c->currency !== 'AZN') (≈ {{ money($c->amount_azn, 'AZN', false) }}, CBAR {{ rate_fmt($c->cbar_rate) }} — {{ azdate($c->rate_date) }})@endif</td></tr>
        <tr><td class="k">{{ __('Müddət') }}</td><td>{{ azdate($c->start_date) }} — {{ azdate($c->end_date) }} {{ $c->auto_renew ? __('· avtomatik uzadılır') : '' }}</td></tr>
        <tr><td class="k">{{ __('Ödəniş şərtləri') }}</td><td>{{ $c->payment_terms ?? '—' }}</td></tr>
        <tr><td class="k">{{ __('Layihə') }}</td><td>{{ $c->project ? $c->project->code.' · '.$c->project->name : '—' }}</td></tr>
        <tr><td class="k">{{ __('Məsul şəxs') }}</td><td>{{ $c->responsible?->name ?? '—' }}</td></tr>
    </table>

    @if($c->payments->isNotEmpty())
        <div class="section">{{ __('Ödəniş qrafiki') }}</div>
        <table class="data">
            <thead><tr><th>#</th><th>{{ __('Tarix') }}</th><th>{{ __('Qeyd') }}</th><th class="num">{{ __('Məbləğ (') }}{{ $c->currency }})</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody>
            @foreach($c->payments as $i => $p)
                <tr><td>{{ $i + 1 }}</td><td>{{ azdate($p->due_date) }}</td><td>{{ $p->note }}</td><td class="num">{{ num($p->amount) }}</td><td>{{ $p->paid_at ? __('Ödənilib ').azdate($p->paid_at) : __('Gözləmədə') }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><td colspan="3">{{ __('Cəmi') }}</td><td class="num">{{ num($c->payments->sum('amount')) }}</td><td></td></tr></tfoot>
        </table>
    @endif

    @if($c->notes)
        <div class="section">{{ __('Qeydlər') }}</div>
        <p>{!! nl2br(e($c->notes)) !!}</p>
    @endif

    <table width="100%" style="margin-top:40px"><tr>
        <td width="50%">______________________<br><span style="color:#667085">{{ $company->name }}</span></td>
        <td width="50%" style="text-align:right">______________________<br><span style="color:#667085">{{ $cp->name }}</span></td>
    </tr></table>
@endsection
