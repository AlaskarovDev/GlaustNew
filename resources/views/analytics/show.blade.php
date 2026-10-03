<x-layouts.app :title="$title">
    <x-page-header :title="$title" :icon="$icon" :subtitle="$description"/>

    <nav class="flex gap-2 overflow-x-auto pb-1 mb-6" aria-label="Hesabatlar">
        @foreach($all as $key => [$label, $ico])
            <a href="{{ route('analytics.show', $key) }}" @class(['badge !h-9 !px-3.5 !text-[13px] gap-1.5 shrink-0', 'badge-teal' => $key === $report, 'badge-slate hover:!text-ink' => $key !== $report]) @if($key === $report) aria-current="page" @endif>
                <x-icon :name="$ico" class="size-4"/> {{ $label }}
            </a>
        @endforeach
    </nav>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
        <section class="card p-8 text-center border-dashed">
            <span class="mx-auto grid place-items-center size-14 rounded-2xl bg-brand-soft text-brand-ink"><x-icon :name="$icon" class="size-7"/></span>
            <h2 class="mt-4 text-lg font-semibold">{{ $title }}</h2>
            <p class="mt-1 text-sm text-muted max-w-lg mx-auto">{{ $description }}</p>
            <div class="mt-5 inline-flex items-center gap-2 rounded-full bg-saffron-soft text-saffron px-3.5 py-1.5 text-xs font-medium">
                <x-icon name="clock" class="size-4"/> Hesablama qaydaları təsdiqləndikdən sonra aktivləşəcək
            </div>
        </section>

        <aside class="card p-5 space-y-3">
            <h3 class="text-sm font-semibold">Hesabata daxil olacaq</h3>
            <ul class="space-y-2 text-sm">
                @foreach($contents as $line)
                    <li class="flex items-start gap-2"><x-icon name="check" class="size-4 text-brand mt-0.5 shrink-0"/><span>{{ $line }}</span></li>
                @endforeach
            </ul>
            <p class="text-[11px] text-faint pt-2 border-t border-line">Məlumatlar artıq toplanır: bank hərəkətləri, CBAR və bank kursları, ödənişlər, komissiyalar və xərclər bazada saxlanılır.</p>
        </aside>
    </div>
</x-layouts.app>
