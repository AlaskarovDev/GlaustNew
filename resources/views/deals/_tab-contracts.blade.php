{{-- The deal's two contracts: purchase (with the seller) and sale (with the buyer). --}}
    <div class="grid xl:grid-cols-2 gap-6 mb-6">
        @foreach([['Alış müqaviləsi', 'satıcı ilə', $deal->purchaseContract, 'bg-saffron'], ['Satış müqaviləsi', 'alıcı ilə', $deal->saleContract, 'bg-brand']] as [$title, $with, $c, $bar])
            <section class="card relative overflow-hidden">
                <div class="absolute inset-x-0 top-0 h-1 {{ $bar }}"></div>
                <header class="flex items-center justify-between px-5 pt-5">
                    <h2 class="text-sm font-semibold">{{ $title }} <span class="text-muted font-normal">— {{ $with }}</span></h2>
                    @if($c)<x-status group="contract" :value="$c->status"/>@endif
                </header>
                @if($c)
                    <div class="px-5 py-4">
                        <a href="{{ route('contracts.show', $c) }}" class="font-mono font-semibold hover:text-brand-ink">{{ $c->number }}</a>
                        <div class="text-sm text-ink-2">{{ $c->subject }}</div>
                        <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                            <span><span class="text-muted">{{ __('Məbləğ:') }}</span> <span class="font-mono">{{ money($c->amount, $c->currency) }}</span></span>
                            <span><span class="text-muted">AZN:</span> <span class="font-mono">{{ money($c->amount_azn) }}</span></span>
                            <span><span class="text-muted">{{ __('Tarix:') }}</span> <span class="font-mono">{{ azdate($c->contract_date) }}</span></span>
                        </div>
                        <div class="mt-4 space-y-1.5">
                            <div class="text-xs font-medium text-muted">{{ __('Müqavilə sənədləri') }}</div>
                            @forelse($c->attachments as $f)
                                <a href="{{ route('attachments.download', $f) }}" class="flex items-center gap-2 text-sm hover:text-brand-ink"><x-icon name="file-pdf" class="size-4 text-danger"/> {{ $f->original_name }} <span class="text-xs text-faint">{{ $f->humanSize() }}</span></a>
                            @empty
                                <p class="text-xs text-faint">{{ __('İmzalı PDF yüklənməyib — «Redaktə» ilə əlavə edin.') }}</p>
                            @endforelse
                            <a href="{{ route('contracts.pdf', $c) }}" target="_blank" class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-ink hover:underline"><x-icon name="printer" class="size-3.5"/> {{ __('Müqavilə kartı (PDF)') }}</a>
                        </div>
                    </div>
                @else
                    <div class="px-5 py-6 text-sm text-muted">Seçilməyib. @can('projects.update')<a href="{{ route('deals.edit', $deal) }}" class="text-brand-ink hover:underline">{{ __('Müqavilə seçin') }}</a>@endcan</div>
                @endif
            </section>
        @endforeach
    </div>
