<x-layouts.app title="Bank hesabları">
    <x-page-header title="Bank hesabları" icon="wallet" subtitle="Şirkətin bankları və onların daxilində valyuta üzrə hesablar">
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
        @php
            $total = $accounts->where('is_active', true)->sum('azn');
            $banks = $accounts->groupBy(fn ($a) => trim($a->bank_name))->sortKeys();
        @endphp
        <div class="card p-5 mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="text-xs text-muted">Aktiv hesablar üzrə cəmi (bugünkü CBAR məzənnəsi ilə)</div>
                <div class="text-2xl font-semibold font-mono"><span x-data x-countup="{{ $total }}" data-decimals="2" data-suffix=" ₼">{{ money($total) }}</span></div>
            </div>
            <div class="flex flex-wrap gap-2">
                <span class="badge badge-slate">{{ $banks->count() }} bank</span>
                <span class="badge badge-slate">{{ $accounts->count() }} hesab</span>
                @foreach($accounts->where('is_active', true)->groupBy('currency') as $cur => $g)
                    <span class="badge badge-teal font-mono">{{ money($g->sum(fn ($a) => $a->currentBalance()), $cur) }}</span>
                @endforeach
            </div>
            @if($accounts->whereNull('azn')->isNotEmpty())<span class="badge badge-amber w-full sm:w-auto">Bəzi valyutalar üçün məzənnə tapılmadı — cəmə daxil deyil</span>@endif
        </div>

        <div class="space-y-6 stagger">
            @foreach($banks as $bank => $list)
                <section class="card overflow-hidden" style="--i:{{ $loop->index }}">
                    <header class="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-line bg-surface-2/50">
                        <span class="grid place-items-center size-10 rounded-xl bg-brand-soft text-brand-ink shrink-0"><x-icon name="bank" class="size-5"/></span>
                        <div class="min-w-0 flex-1">
                            <h2 class="font-semibold truncate">{{ $bank }}</h2>
                            <p class="text-xs text-muted">{{ $list->count() }} hesab · {{ $list->pluck('currency')->unique()->sort()->implode(', ') }}</p>
                        </div>
                        <div class="text-right">
                            <div class="text-[11px] text-muted">Cəmi, AZN ekvivalenti</div>
                            <div class="font-mono font-semibold">{{ money($list->where('is_active', true)->sum('azn')) }}</div>
                        </div>
                    </header>
                    <ul class="divide-y divide-line">
                        @foreach($list->sortBy([['is_active', 'desc'], ['currency', 'asc'], ['name', 'asc']]) as $a)
                            @php $bal = $a->currentBalance(); @endphp
                            <li @class(['flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4', 'opacity-60' => ! $a->is_active])>
                                <span class="grid place-items-center w-14 h-9 rounded-lg bg-surface-2 font-mono text-sm font-semibold shrink-0">{{ $a->currency }}</span>
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('bank.accounts.statement', $a) }}" class="font-medium hover:text-brand-ink">{{ $a->name }}</a>
                                    @unless($a->is_active)<span class="badge badge-slate ml-1">Deaktiv</span>@endunless
                                    <div class="text-xs text-muted font-mono break-all">{{ $a->iban ? trim(chunk_split($a->iban, 4, ' ')) : 'IBAN göstərilməyib' }}</div>
                                </div>
                                <div class="text-right min-w-[150px]">
                                    <div @class(['font-mono font-semibold', 'text-danger' => $bal < 0])>{{ money($bal, $a->currency) }}</div>
                                    <div class="text-[11px] text-muted">{{ $a->azn !== null ? '≈ '.money($a->azn) : 'məzənnə tapılmadı' }} · {{ $a->transactions_count }} əməliyyat</div>
                                </div>
                                <div class="flex items-center gap-1 ml-auto">
                                    <a href="{{ route('bank.accounts.statement', $a) }}" class="btn btn-secondary btn-sm"><x-icon name="list" class="size-4"/> Çıxarış</a>
                                    @can('bank.update')<a href="{{ route('bank.accounts.edit', $a) }}" class="btn btn-ghost btn-sm btn-icon" aria-label="Redaktə"><x-icon name="pencil" class="size-4"/></a>@endcan
                                    @can('bank.delete')<x-delete-form :action="route('bank.accounts.destroy', $a)" label="" :message="'«'.$a->name.'» silinsin? Əməliyyatı olan hesab yalnız deaktiv edilir.'"/>@endcan
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</x-layouts.app>
