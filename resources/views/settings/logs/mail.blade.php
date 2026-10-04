<x-layouts.app :title="__('Mail jurnalı')">
    @php $kinds = ['reminder' => 'Xatırlatma', 'digest' => 'Gündəlik xülasə', 'invitation' => 'Dəvət', 'password_reset' => 'Şifrə bərpası', 'test' => 'Test']; @endphp
    <x-page-header :title="__('Tənzimləmələr')" icon="settings"/>
    @include('settings._nav')
    <div class="card overflow-hidden">
        <form method="GET" class="flex flex-wrap items-center gap-2 p-4 border-b border-line" x-data>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Alıcı və ya mövzu') }}" class="input !w-60" aria-label="{{ __('Axtarış') }}">
            <select name="kind" class="input !h-9 !w-auto text-[13px]" @change="$el.form.requestSubmit()" aria-label="{{ __('Növ') }}"><option value="">{{ __('Növ: hamısı') }}</option>@foreach($kinds as $k => $v)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $v }}</option>@endforeach</select>
            <select name="status" class="input !h-9 !w-auto text-[13px]" @change="$el.form.requestSubmit()" aria-label="{{ __('Status') }}"><option value="">{{ __('Status: hamısı') }}</option><option value="sent" @selected(request('status') === 'sent')>{{ __('Göndərildi') }}</option><option value="failed" @selected(request('status') === 'failed')>{{ __('Xəta') }}</option></select>
        </form>
        <div class="overflow-x-auto">
            <table class="table-g table-stack">
                <thead><tr><th>{{ __('Tarix') }}</th><th>{{ __('Kimə') }}</th><th>{{ __('Mövzu') }}</th><th>{{ __('Növ') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @forelse($logs as $m)
                    <tr>
                        <td data-label="Tarix" class="font-mono text-xs whitespace-nowrap">{{ azdate($m->created_at, true) }}</td>
                        <td data-label="Kimə" class="text-sm">{{ $m->to }}</td>
                        <td data-label="Mövzu" class="max-w-[320px]"><span class="block truncate">{{ $m->subject }}</span>@if($m->error)<span class="block text-xs text-danger break-words">{{ \Illuminate\Support\Str::limit($m->error, 200) }}</span>@endif</td>
                        <td data-label="Növ" class="text-xs">{{ $kinds[$m->kind] ?? $m->kind }}<div class="text-faint">{{ $m->transport === 'company' ? 'şirkət SMTP' : 'platforma' }}</div></td>
                        <td data-label="Status">@if($m->status === 'sent')<span class="badge badge-green badge-dot">{{ __('Göndərildi') }}</span>@else<span class="badge badge-rose badge-dot">{{ __('Xəta') }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty icon="send" :title="__('Hələ mail göndərilməyib')"/></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $logs->links() }}
        <p class="px-4 py-3 border-t border-line text-xs text-muted">{{ __('«Göndərildi» — mail serveri məktubu qəbul etdi. Alıcının qutusuna çatması ayrıca yoxlanılmalıdır.') }}</p>
    </div>
</x-layouts.app>
