<x-layouts.app title="Bank hesabları">
    <x-page-header title="Bank əməliyyatları" icon="bank" subtitle="Şirkətin bank hesabları və cari qalıqlar">
        <x-slot:actions>
            @can('bank.create')
                <a href="{{ route('bank.accounts.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> Hesab əlavə et</a>
            @endcan
        </x-slot:actions>
    </x-page-header>
    @include('bank._tabs')

    @if($accounts->isEmpty())
        <div class="card"><x-empty icon="bank" title="Bank hesabı yoxdur" text="Bank hesablarınızı və başlanğıc qalıqları daxil edin.">
            @can('bank.create')<a href="{{ route('bank.accounts.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> Hesab əlavə et</a>@endcan
        </x-empty></div>
    @else
        @php $total = $accounts->where('is_active', true)->sum('azn'); @endphp
        <div class="card p-5 mb-5 flex flex-wrap items-center justify-between gap-3">
            <div><div class="text-xs text-muted">Aktiv hesablar üzrə cəmi (bugünkü CBAR məzənnəsi ilə)</div><div class="text-2xl font-semibold font-mono"><span x-data x-countup="{{ $total }}" data-decimals="2" data-suffix=" ₼">{{ money($total) }}</span></div></div>
            @if($accounts->whereNull('azn')->isNotEmpty())<span class="badge badge-amber">Bəzi valyutalar üçün məzənnə tapılmadı — cəmə daxil deyil</span>@endif
        </div>
        <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-5 stagger">
            @foreach($accounts as $a)
                @php $bal = $a->currentBalance(); @endphp
                <article @class(['card p-5 relative overflow-hidden', 'opacity-60' => ! $a->is_active]) style="--i:{{ $loop->index }}">
                    <div class="absolute -right-8 -top-8 size-28 rounded-full bg-brand-soft/70"></div>
                    <div class="relative flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold truncate">{{ $a->name }}</div>
                            <div class="text-sm text-muted truncate">{{ $a->bank_name }}</div>
                        </div>
                        <span class="badge badge-teal font-mono">{{ $a->currency }}</span>
                    </div>
                    <div @class(['relative mt-5 text-2xl font-semibold font-mono tabular', 'text-danger' => $bal < 0])>{{ money($bal, $a->currency) }}</div>
                    <div class="relative text-xs text-muted mt-1">{{ $a->azn !== null ? '≈ '.money($a->azn) : 'məzənnə tapılmadı' }} · {{ $a->transactions_count }} əməliyyat</div>
                    @if($a->iban)<div class="relative mt-3 text-xs font-mono text-muted break-all">{{ trim(chunk_split($a->iban, 4, ' ')) }}</div>@endif
                    <div class="relative mt-4 pt-4 border-t border-line flex items-center gap-2">
                        <a href="{{ route('bank.transactions.index', ['account_id' => $a->id]) }}" class="btn btn-secondary btn-sm">Əməliyyatlar</a>
                        @unless($a->is_active)<span class="badge badge-slate">Deaktiv</span>@endunless
                        <span class="ml-auto flex">
                            @can('bank.update')<a href="{{ route('bank.accounts.edit', $a) }}" class="btn btn-ghost btn-sm btn-icon" aria-label="Redaktə"><x-icon name="pencil" class="size-4"/></a>@endcan
                            @can('bank.delete')<x-delete-form :action="route('bank.accounts.destroy', $a)" label="" :message="'«'.$a->name.'» silinsin? Əməliyyatı olan hesab yalnız deaktiv edilir.'"/>@endcan
                        </span>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</x-layouts.app>
