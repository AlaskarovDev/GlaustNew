<x-layouts.app title="Hesabatlar">
    <x-page-header title="Hesabatlar" icon="chart" subtitle="Bütün məbləğlər Mərkəzi Bankın rəsmi məzənnəsi ilə AZN-ə çevrilir · hər hesabat Excel və PDF-ə export olunur"/>
    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-5 stagger">
        @foreach($reports as $r)
            <a href="{{ route('reports.show', $r::key()) }}" class="card card-hover p-6 group flex flex-col" style="--i:{{ $loop->index }}">
                <span class="grid place-items-center size-12 rounded-2xl bg-brand-soft text-brand group-hover:scale-110 transition-transform"><x-icon :name="$r::icon()" class="size-6"/></span>
                <h2 class="mt-5 font-semibold group-hover:text-brand-ink">{{ $r::title() }}</h2>
                <p class="mt-1.5 text-sm text-muted leading-relaxed flex-1">{{ $r::description() }}</p>
                <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-medium text-brand-ink">Aç <x-icon name="arrow-right" class="size-4 group-hover:translate-x-1 transition-transform"/></span>
            </a>
        @endforeach
    </div>
</x-layouts.app>
