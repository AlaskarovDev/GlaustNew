<x-layouts.app title="Öhdəliklərim">
    @php
        $fmt = fn (array $byCur, string $empty = '0') => $byCur ? collect($byCur)->map(fn ($v, $c) => money($v, $c))->implode(' · ') : $empty;
        $first = collect($groups)->search(fn ($g) => $g['total']) ?: 'pay';
    @endphp
    <x-page-header title="Öhdəliklərim" icon="scale" subtitle="Bütün tədarüklər üzrə: kimə nə qədər ödəməliyəm, kimi məhsulla təmin etməliyəm, kimdən nə qədər ödəniş gəlməlidir — hər valyutada ayrıca"/>

    <div x-data="{ sel: @js(request('group', $first)) }">
        <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6" role="tablist" aria-label="Öhdəlik növləri">
            @foreach($meta as $key => [$title, $sub, $icon, $tone])
                @php $g = $groups[$key]; $n = collect($g['projects'])->sum(fn ($p) => count($p['deals'])); @endphp
                <button type="button" role="tab" :aria-selected="sel === '{{ $key }}'" @click="sel = '{{ $key }}'"
                        class="card ob-card tone-{{ $tone }} text-left" :class="sel === '{{ $key }}' && 'is-on'">
                    <span class="ob-card-icon"><x-icon :name="$icon" class="size-5"/></span>
                    <span class="block text-sm font-semibold mt-3">{{ $title }}</span>
                    <span class="block text-[11px] text-muted">{{ $sub }}</span>
                    <span class="block mt-3 font-mono text-lg font-semibold leading-snug">{{ $fmt($g['total']) }}</span>
                    <span class="block text-[11px] text-muted mt-1">{{ $n }} tədarük · {{ count($g['projects']) }} layihə</span>
                </button>
            @endforeach
        </div>

        @foreach($meta as $key => [$title])
            @php $g = $groups[$key]; @endphp
            <section x-show="sel === '{{ $key }}'" @if($key !== $first) x-cloak @endif role="tabpanel" aria-label="{{ $title }}">
                <div class="flex items-baseline justify-between gap-3 mb-3">
                    <h2 class="text-base font-semibold">{{ $title }}</h2>
                    <span class="text-sm text-muted">Cəmi: <b class="font-mono text-ink">{{ $fmt($g['total']) }}</b></span>
                </div>
                @if(! $g['projects'])
                    <div class="card"><x-empty icon="check-circle" title="Öhdəlik yoxdur" text="Bu növ üzrə açıq öhdəlik yoxdur."/></div>
                @else
                    <div class="space-y-4">
                        @foreach($g['projects'] as $p)
                            <article class="card overflow-hidden">
                                <header class="flex flex-wrap items-center gap-3 px-5 py-3 border-b border-line bg-surface-2/50">
                                    <x-icon name="folder" class="size-4 text-muted"/>
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('projects.show', [$p['project'], 'tab' => 'deals']) }}" class="font-semibold hover:text-brand-ink">{{ $p['project']?->name }}</a>
                                        <span class="text-xs font-mono text-muted ml-1">{{ $p['project']?->code }}</span>
                                    </div>
                                    <span class="font-mono text-sm font-semibold">{{ $fmt($p['total']) }}</span>
                                </header>
                                <ul class="divide-y divide-line">
                                    @foreach($p['deals'] as $row)
                                        <li class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                                            <div class="min-w-0 flex-1">
                                                <a href="{{ route('deals.show', $row['deal']) }}" class="font-medium hover:text-brand-ink"><span class="font-mono text-xs text-muted">{{ $row['deal']->code }}</span> {{ $row['deal']->title }}</a>
                                                <div class="text-xs text-muted">{{ $row['what'] }} · <span class="text-ink-2">{{ $row['who'] ?? '—' }}</span></div>
                                            </div>
                                            <span class="font-mono font-semibold text-sm">{{ $fmt($row['amounts']) }}</span>
                                            <a href="{{ route('projects.show', [$p['project'], 'tab' => 'deals']) }}#deal-{{ $row['deal']->id }}" class="btn btn-secondary btn-sm">Ətraflı bax <x-icon name="arrow-right" class="size-3.5"/></a>
                                        </li>
                                    @endforeach
                                </ul>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    </div>
</x-layouts.app>
