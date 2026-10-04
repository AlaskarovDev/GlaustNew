@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Səhifələmə') }}" class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-line">
        <p class="text-sm text-muted">
            <span class="font-mono tabular text-ink-2">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
            / <span class="font-mono tabular text-ink-2">{{ $paginator->total() }}</span> {{ __('qeyd') }}
        </p>
        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="btn btn-ghost btn-sm btn-icon opacity-40" aria-disabled="true"><x-icon name="chevron-left" class="size-4"/></span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-ghost btn-sm btn-icon" aria-label="{{ __('Əvvəlki') }}"><x-icon name="chevron-left" class="size-4"/></a>
            @endif
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-1 text-faint">…</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="btn btn-sm min-w-8 !px-2 bg-brand-soft text-brand-ink font-mono">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="btn btn-ghost btn-sm min-w-8 !px-2 font-mono">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-ghost btn-sm btn-icon" aria-label="{{ __('Növbəti') }}"><x-icon name="chevron-right" class="size-4"/></a>
            @else
                <span class="btn btn-ghost btn-sm btn-icon opacity-40" aria-disabled="true"><x-icon name="chevron-right" class="size-4"/></span>
            @endif
        </div>
    </nav>
@endif
