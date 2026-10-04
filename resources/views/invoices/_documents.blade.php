{{-- The buyer's documents generated from this calculation: proforma (EN) + specification (RU), both editable. --}}
@php $docs = $invoice->salesDocuments; @endphp
<section class="card mb-6 overflow-hidden" id="documents">
    <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-line">
        <div>
            <h2 class="text-base font-semibold flex items-center gap-2"><x-icon name="send" class="size-5 text-brand"/> {{ __('Alıcı üçün sənədlər') }}</h2>
            <p class="text-xs text-muted">{{ __('3 addım tamamlananda avtomatik yaradılır; sonra hər sahə redaktə edilə və PDF yenidən yüklənə bilər.') }}</p>
        </div>
        @if(session('documents_created'))
            <span class="badge badge-green">{{ __('Yeni yaradıldı:') }} {{ implode(', ', session('documents_created')) }}</span>
        @endif
    </header>

    @if($docs->isNotEmpty())
        <div class="grid md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-line">
            @foreach($docs as $d)
                <div class="p-5 flex items-start gap-4">
                    <span class="grid place-items-center size-11 shrink-0 rounded-xl {{ $d->isProforma() ? 'bg-brand-soft text-brand-ink' : 'bg-saffron-soft text-saffron' }}"><x-icon name="file-pdf" class="size-5"/></span>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold">{{ $d->title() }} <span class="font-mono">{{ $d->number }}</span></div>
                        <div class="text-xs text-muted">{{ $d->label() }} · {{ azdate($d->doc_date) }} · <span class="font-mono text-ink">{{ money($d->grandTotal(), $d->currency) }}</span></div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a href="{{ route('sales-documents.show', $d) }}" class="btn btn-secondary btn-sm"><x-icon name="pencil" class="size-3.5"/> {{ __('Aç və redaktə et') }}</a>
                            <a href="{{ route('sales-documents.pdf', $d) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="size-4"/> PDF</a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if($docs->whereIn('kind', \App\Models\SalesDocument::AUTO_KINDS)->count() < count(\App\Models\SalesDocument::AUTO_KINDS))
        <div class="px-5 py-4 {{ $docs->isNotEmpty() ? 'border-t border-line' : '' }} flex flex-wrap items-center gap-3 text-sm">
            @if($invoice->rubReady())
                <span class="flex-1 text-muted">{{ $docs->isEmpty() ? __('Sənədlər hələ yaradılmayıb.') : __('Silinmiş sənəd var.') }}</span>
                @if(auth()->user()->can('projects.update') && ! $invoice->isLocked())
                    <form method="POST" action="{{ route('invoices.documents', $invoice) }}">@csrf
                        <button class="btn btn-primary btn-sm"><x-icon name="sparkles" class="size-4"/> {{ __('Hesablamadan yarat') }}</button>
                    </form>
                @endif
            @else
                <span class="text-muted">{{ __('Proforma faktura və spesifikasiya logistika, komissiya və RUB konvertasiyası tətbiq olunan kimi burada avtomatik yaranacaq.') }}</span>
            @endif
        </div>
    @endif
</section>
