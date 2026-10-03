@props(['title' => null, 'wide' => false])
@use('App\Support\Navigation')
@php
    $user = auth()->user();
    $sidebar = Navigation::sidebar($user);
    $flash = collect(['success', 'error', 'info', 'warning'])
        ->filter(fn ($k) => session()->has($k))
        ->map(fn ($k) => ['type' => $k, 'message' => session($k)])->values();
    $theme = $user->theme ?: 'system';
@endphp
<!DOCTYPE html>
<html lang="az" data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="{{ \App\Support\Csp::policy(true) }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}Glaust MS</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <script nonce="{{ Vite::cspNonce() }}">
        (() => {
            let mode = @json($theme);
            try { mode = localStorage.getItem('glaust-theme') || mode; } catch (e) {}
            const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
            document.documentElement.dataset.theme = mode;
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data="{ nav: false, collapsed: false }" x-init="collapsed = glaustPref.get('glaust-nav') === '1'" class="overflow-x-hidden">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[100] btn btn-primary">Əsas məzmuna keç</a>

{{-- Mobile backdrop --}}
<div x-cloak x-show="nav" x-transition.opacity class="fixed inset-0 z-40 bg-night/60 backdrop-blur-sm lg:hidden" @click="nav = false"></div>

{{-- Sidebar --}}
<aside class="no-print fixed inset-y-0 left-0 z-50 flex flex-col bg-night text-slate-300 border-r border-night-line transition-[width,transform] duration-300 ease-out -translate-x-full lg:translate-x-0"
       :class="{ 'translate-x-0': nav, 'lg:w-[76px]': collapsed, 'w-[264px]': true }"
       aria-label="Əsas naviqasiya">
    <div class="flex items-center gap-3 h-16 px-5 border-b border-night-line shrink-0">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-3 min-w-0">
            <span class="relative grid place-items-center size-9 shrink-0 rounded-[11px] bg-gradient-to-br from-teal-400 to-teal-700 text-night font-bold shadow-[0_0_24px_-4px_#2dd4bf]">
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M17 7.5A6.5 6.5 0 1 0 18.5 13H12"/></svg>
            </span>
            <span class="leading-tight min-w-0" :class="collapsed && 'lg:hidden'">
                <span class="block text-[15px] font-semibold text-white tracking-tight">Glaust</span>
                <span class="block text-[11px] text-slate-500 truncate">{{ $company->name }}</span>
            </span>
        </a>
        <button type="button" class="ml-auto lg:hidden text-slate-400 hover:text-white" @click="nav = false" aria-label="Menyunu bağla">
            <x-icon name="x" class="size-5"/>
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto overscroll-contain px-3 py-4 space-y-5">
        @foreach($sidebar as $section)
            <div>
                @if($section['label'])
                    <div class="px-3 mb-1.5 text-[11px] font-semibold uppercase tracking-[.09em] text-slate-600" :class="collapsed && 'lg:invisible'">{{ $section['label'] }}</div>
                @endif
                <ul class="space-y-0.5">
                    @foreach($section['items'] as $item)
                        @php $active = request()->routeIs(...explode('|', $item['active'])); @endphp
                        @if(! empty($item['children']))
                            {{-- Group with sub-items; opens by itself when one of them is the current page. --}}
                            <li x-data="{ open: {{ $active ? 'true' : 'false' }} }">
                                <button type="button" @click="collapsed ? (window.location = @js(route($item['route'], $item['params'] ?? []))) : (open = !open)"
                                        @class(['nav-link w-full', 'is-active' => $active]) :aria-expanded="open" :class="collapsed && 'lg:justify-center lg:px-0'" :title="collapsed ? @js($item['label']) : null">
                                    <x-icon :name="$item['icon']" class="size-[19px] shrink-0"/>
                                    <span class="truncate flex-1 text-left" :class="collapsed && 'lg:hidden'">{{ $item['label'] }}</span>
                                    <x-icon name="chevron-down" class="size-4 shrink-0 opacity-60 transition-transform duration-200" ::class="[open && 'rotate-180', collapsed && 'lg:hidden']"/>
                                </button>
                                <ul x-show="open && !collapsed" x-collapse @if(! $active) x-cloak @endif class="nav-sub">
                                    @foreach($item['children'] as $child)
                                        @php
                                            $childActive = isset($child['active']) ? request()->routeIs($child['active'])
                                                : (request()->routeIs($child['route']) && collect($child['params'] ?? [])->every(fn ($v, $k) => (string) request()->route($k) === (string) $v));
                                        @endphp
                                        <li>
                                            <a href="{{ route($child['route'], $child['params'] ?? []) }}" @class(['nav-sublink', 'is-active' => $childActive]) @if($childActive) aria-current="page" @endif>
                                                <x-icon :name="$child['icon']" class="size-4 shrink-0"/><span class="truncate">{{ $child['label'] }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @else
                            <li>
                                <a href="{{ route($item['route'], $item['params'] ?? []) }}" @class(['nav-link', 'is-active' => $active]) @if($active) aria-current="page" @endif
                                   :class="collapsed && 'lg:justify-center lg:px-0'" :title="collapsed ? @js($item['label']) : null">
                                    <x-icon :name="$item['icon']" class="size-[19px] shrink-0"/>
                                    <span class="truncate" :class="collapsed && 'lg:hidden'">{{ $item['label'] }}</span>
                                </a>
                            </li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    <div class="shrink-0 border-t border-night-line p-3 space-y-2">
        @if($company->subscription_status === 'trial' && $company->trial_ends_at)
            <div class="rounded-xl bg-white/[0.04] border border-white/[0.06] px-3 py-2.5 text-xs" :class="collapsed && 'lg:hidden'">
                <div class="flex items-center gap-2 text-amber-300 font-medium"><x-icon name="sparkles" class="size-4"/> Sınaq müddəti</div>
                <div class="mt-1 text-slate-400">{{ max(0, (int) now()->startOfDay()->diffInDays($company->trial_ends_at, false)) }} gün qalıb · {{ $company->plan?->name }}</div>
            </div>
        @endif
        <button type="button" class="hidden lg:flex nav-link w-full" @click="collapsed = !collapsed; glaustPref.set('glaust-nav', collapsed ? '1' : '0')"
                :class="collapsed && 'lg:justify-center lg:px-0'" :aria-label="collapsed ? 'Menyunu genişləndir' : 'Menyunu daralt'">
            <x-icon name="chevrons-left" class="size-[19px] shrink-0 transition-transform duration-300" ::class="collapsed && 'rotate-180'"/>
            <span :class="collapsed && 'lg:hidden'">Daralt</span>
        </button>
    </div>
</aside>

{{-- Main column --}}
<div class="transition-[padding] duration-300 ease-out lg:pl-[264px]" :class="collapsed && 'lg:!pl-[76px]'">

    {{-- Header --}}
    <header class="no-print sticky top-0 z-30 h-16 bg-canvas/85 backdrop-blur-xl border-b border-line">
        <div class="h-full flex items-center gap-2 sm:gap-3 px-4 sm:px-6">
            <button type="button" class="btn btn-ghost btn-icon lg:hidden -ml-2" @click="nav = true" aria-label="Menyunu aç">
                <x-icon name="menu" class="size-5"/>
            </button>

            {{-- Live CBAR ticker --}}
            <div x-data="ticker" class="ticker-wrap flex-1 min-w-0 flex items-center gap-3" aria-live="polite">
                <a href="{{ route('currency.index') }}" class="hidden md:flex items-center gap-2 shrink-0 h-8 pl-2 pr-2.5 rounded-lg bg-surface border border-line text-xs font-medium text-ink-2 hover:border-line-strong transition-colors"
                   title="Azərbaycan Respublikası Mərkəzi Bankının rəsmi məzənnələri">
                    <span class="relative flex size-2">
                        <span class="absolute inline-flex size-full rounded-full opacity-60" :class="stale || !ok ? 'bg-saffron' : 'bg-brand animate-ping'"></span>
                        <span class="relative inline-flex size-2 rounded-full" :class="stale || !ok ? 'bg-saffron' : 'bg-brand'"></span>
                    </span>
                    CBAR <span class="font-mono text-muted" x-text="dateLabel"></span>
                </a>

                {{-- desktop: scrolling strip --}}
                <div class="hidden md:block flex-1 min-w-0 overflow-hidden ticker-mask ticker-scroll">
                    <template x-if="loading">
                        <div class="flex gap-6"><div class="skeleton h-4 w-28"></div><div class="skeleton h-4 w-28"></div><div class="skeleton h-4 w-28"></div></div>
                    </template>
                    <template x-if="!loading && !items.length">
                        <div class="text-sm text-muted flex items-center gap-2"><x-icon name="alert" class="size-4 text-saffron"/> CBAR əlçatan deyil</div>
                    </template>
                    <div x-show="!loading && items.length" class="ticker-track flex w-max" :style="`--ticker-duration: ${duration}`">
                        <template x-for="copy in [0, 1]" :key="copy">
                            <ul class="flex shrink-0" :aria-hidden="copy === 1">
                                <template x-for="item in items" :key="copy + item.code">
                                    <li class="flex items-center gap-2 pr-7 text-sm whitespace-nowrap">
                                        <span class="font-semibold text-ink" x-text="item.code"></span>
                                        <span class="font-mono tabular text-ink-2" x-text="rate(item.rate)"></span>
                                        <span class="inline-flex items-center gap-0.5 font-mono text-xs tabular"
                                              :class="{ 'text-success': item.trend === 'up', 'text-danger': item.trend === 'down', 'text-faint': item.trend === 'flat' }">
                                            <span x-text="item.trend === 'up' ? '▲' : (item.trend === 'down' ? '▼' : '•')"></span>
                                            <span x-text="change(item.change)"></span>
                                        </span>
                                    </li>
                                </template>
                            </ul>
                        </template>
                    </div>
                </div>
                <template x-if="stale && date">
                    <span class="hidden xl:inline-flex badge badge-amber shrink-0" x-text="'Son məlumat: ' + dateLabel"></span>
                </template>

                {{-- mobile: one flipping currency --}}
                <a href="{{ route('currency.index') }}" class="md:hidden flex items-center gap-2 h-8 px-2.5 rounded-lg bg-surface border border-line min-w-0 overflow-hidden">
                    <template x-for="(item, i) in items" :key="item.code">
                        <span x-show="i === focus" class="flip-in flex items-center gap-1.5 text-[13px] whitespace-nowrap">
                            <span class="font-semibold" x-text="item.code"></span>
                            <span class="font-mono tabular" x-text="rate(item.rate)"></span>
                            <span class="font-mono text-[11px]" :class="{ 'text-success': item.trend === 'up', 'text-danger': item.trend === 'down', 'text-faint': item.trend === 'flat' }"
                                  x-text="item.trend === 'up' ? '▲' : (item.trend === 'down' ? '▼' : '•')"></span>
                        </span>
                    </template>
                    <span x-show="!items.length" class="text-xs text-muted">₼ CBAR</span>
                </a>
            </div>

            {{-- Search / command palette --}}
            <button type="button" @click="$dispatch('glaust:palette')" class="hidden sm:flex items-center gap-2 h-9 pl-3 pr-2 rounded-[10px] border border-line bg-surface text-sm text-muted hover:border-line-strong transition-colors w-56 xl:w-64">
                <x-icon name="search" class="size-4"/>
                <span>Axtar və ya əmr…</span>
                <span class="ml-auto flex gap-0.5"><span class="kbd">Ctrl</span><span class="kbd">K</span></span>
            </button>
            <button type="button" @click="$dispatch('glaust:palette')" class="btn btn-ghost btn-icon sm:hidden" aria-label="Axtar">
                <x-icon name="search" class="size-5"/>
            </button>

            {{-- Today: tasks + reminders --}}
            <div x-data="myDay" class="relative flex items-center gap-1" @keydown.escape.window="open = false" @click.outside="open = false">
                <button type="button" @click="toggle('tasks')" class="relative flex items-center gap-2 h-9 pl-2.5 pr-3 rounded-[10px] text-sm font-medium transition-colors"
                        :class="open && tab === 'tasks' ? 'bg-brand-soft text-brand-ink' : 'text-ink-2 hover:bg-surface-2'" aria-label="Bugünkü işlərim" :aria-expanded="open && tab === 'tasks'">
                    <x-icon name="calendar-check" class="size-[19px]"/>
                    <span class="hidden md:inline">Bugün</span>
                    <span x-show="counts.tasks > 0" x-cloak class="grid place-items-center min-w-5 h-5 px-1 rounded-full text-[11px] font-semibold font-mono"
                          :class="counts.overdue > 0 ? 'bg-danger text-white' : 'bg-saffron text-night'" x-text="counts.tasks"></span>
                </button>
                <button type="button" @click="toggle('reminders')" class="relative grid place-items-center size-9 rounded-[10px] transition-colors"
                        :class="open && tab === 'reminders' ? 'bg-brand-soft text-brand-ink' : 'text-ink-2 hover:bg-surface-2'" aria-label="Xatırlatmalar" :aria-expanded="open && tab === 'reminders'">
                    <span :class="ringing && 'animate-ring'" class="origin-top"><x-icon name="bell" class="size-[19px]"/></span>
                    <span x-show="counts.reminders > 0" x-cloak class="absolute top-1.5 right-1.5 size-2.5 rounded-full bg-saffron ring-2 ring-canvas animate-pulse-dot"></span>
                    <span class="sr-only" x-text="counts.reminders + ' xatırlatma'"></span>
                </button>

                {{-- Panel --}}
                <div x-cloak x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2 scale-[.98]" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                     x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0 -translate-y-1"
                     class="fixed sm:absolute left-3 right-3 sm:left-auto sm:right-0 top-[68px] sm:top-12 sm:w-[420px] card !shadow-[var(--shadow-pop)] overflow-hidden origin-top-right">
                    <div class="flex items-center gap-1 p-1.5 border-b border-line bg-surface-2">
                        <button type="button" @click="tab = 'tasks'" class="flex-1 h-8 rounded-lg text-sm font-medium transition-colors" :class="tab === 'tasks' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink'">
                            İşlərim <span class="font-mono text-xs" x-text="'(' + counts.tasks + ')'"></span>
                        </button>
                        <button type="button" @click="tab = 'reminders'" class="flex-1 h-8 rounded-lg text-sm font-medium transition-colors" :class="tab === 'reminders' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink'">
                            Xatırlatmalar <span class="font-mono text-xs" x-text="'(' + counts.reminders + ')'"></span>
                        </button>
                    </div>
                    <div class="max-h-[min(70vh,520px)] overflow-y-auto overscroll-contain">
                        <template x-if="loading"><div class="p-4 space-y-3"><div class="skeleton h-12"></div><div class="skeleton h-12"></div></div></template>

                        {{-- tasks --}}
                        <div x-show="tab === 'tasks' && !loading">
                            <template x-for="group in [['overdue', 'Gecikmiş', 'text-danger'], ['today', 'Bu gün', 'text-brand-ink'], ['upcoming', 'Yaxın 3 gün', 'text-muted']]" :key="group[0]">
                                <div x-show="tasks[group[0]].length">
                                    <div class="px-4 pt-3 pb-1 text-[11px] font-semibold uppercase tracking-wider" :class="group[2]" x-text="group[1] + ' · ' + tasks[group[0]].length"></div>
                                    <ul>
                                        <template x-for="(task, i) in tasks[group[0]]" :key="task.id">
                                            <li class="group flex items-start gap-3 px-4 py-2.5 hover:bg-surface-2 transition-colors rise" :style="`--i:${i}`">
                                                <button type="button" @click="complete(task)" class="mt-0.5 grid place-items-center size-5 shrink-0 rounded-full border-2 transition-all"
                                                        :class="task.done ? 'bg-success border-success text-white scale-110' : 'border-line-strong hover:border-brand text-transparent hover:text-brand'" :aria-label="'Tamamla: ' + task.title">
                                                    <x-icon name="check" class="size-3" :stroke="3"/>
                                                </button>
                                                <a :href="task.url" class="min-w-0 flex-1">
                                                    <div class="text-sm font-medium text-ink truncate transition-all" :class="task.done && 'line-through text-faint'" x-text="task.title"></div>
                                                    <div class="mt-0.5 flex items-center gap-2 text-xs text-muted">
                                                        <span x-show="task.project" class="truncate" x-text="task.project"></span>
                                                        <span class="font-mono shrink-0" :class="group[0] === 'overdue' && 'text-danger'" x-text="task.due"></span>
                                                    </div>
                                                </a>
                                                <span class="badge shrink-0" :class="'badge-' + task.priority_color" x-text="task.priority"></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>
                            <div x-show="!tasks.overdue.length && !tasks.today.length && !tasks.upcoming.length" class="px-6 py-10 text-center">
                                <div class="mx-auto grid place-items-center size-12 rounded-2xl bg-success-soft text-success mb-3"><x-icon name="check-circle" class="size-6"/></div>
                                <div class="text-sm font-medium text-ink">Bu gün üçün iş yoxdur</div>
                                <div class="text-xs text-muted mt-1">Hər şey qaydasındadır.</div>
                            </div>
                        </div>

                        {{-- reminders --}}
                        <div x-show="tab === 'reminders' && !loading">
                            <ul>
                                <template x-for="(r, i) in reminders" :key="r.id">
                                    <li class="px-4 py-3 border-b border-line last:border-0 rise" :style="`--i:${i}`">
                                        <div class="flex items-start gap-3">
                                            <span class="mt-0.5 grid place-items-center size-8 shrink-0 rounded-lg" :class="r.overdue ? 'bg-danger-soft text-danger' : 'bg-saffron-soft text-saffron'">
                                                <x-icon name="bell" class="size-4"/>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <a :href="r.url || '#'" class="block text-sm font-medium text-ink hover:text-brand-ink" x-text="r.title"></a>
                                                <div class="text-xs text-muted mt-0.5" x-text="r.body"></div>
                                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                                    <span class="badge badge-slate" x-text="r.source"></span>
                                                    <span class="text-[11px] font-mono" :class="r.overdue ? 'text-danger' : 'text-faint'" x-text="r.when"></span>
                                                    <span class="ml-auto flex gap-1">
                                                        <button type="button" @click="snooze(r, 60)" class="btn btn-ghost btn-sm !h-7 !px-2" title="1 saat ertələ"><x-icon name="snooze" class="size-3.5"/> 1 saat</button>
                                                        <button type="button" @click="snooze(r, 1440)" class="btn btn-ghost btn-sm !h-7 !px-2" title="Sabaha ertələ">Sabah</button>
                                                        <button type="button" @click="read(r)" class="btn btn-secondary btn-sm !h-7 !px-2"><x-icon name="check" class="size-3.5"/> Oxundu</button>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                            <div x-show="!reminders.length" class="px-6 py-10 text-center">
                                <div class="mx-auto grid place-items-center size-12 rounded-2xl bg-brand-soft text-brand mb-3"><x-icon name="bell" class="size-6"/></div>
                                <div class="text-sm font-medium text-ink">Aktiv xatırlatma yoxdur</div>
                            </div>
                        </div>
                    </div>
                    <a href="{{ route('my-work') }}" class="flex items-center justify-center gap-1.5 h-11 border-t border-line text-sm font-medium text-brand-ink hover:bg-surface-2 transition-colors">
                        Hamısına bax <x-icon name="arrow-right" class="size-4"/>
                    </a>
                </div>
            </div>

            {{-- User menu --}}
            <div x-data="{ open: false }" class="relative" @keydown.escape.window="open = false" @click.outside="open = false">
                <button type="button" @click="open = !open" class="flex items-center gap-2 h-9 pl-1 pr-1 sm:pr-2 rounded-[10px] hover:bg-surface-2 transition-colors" :aria-expanded="open" aria-label="Profil menyusu">
                    <x-avatar :user="$user" size="sm"/>
                    <x-icon name="chevron-down" class="hidden sm:block size-4 text-muted"/>
                </button>
                <div x-cloak x-show="open" x-transition.origin.top.right class="absolute right-0 top-12 w-64 card !shadow-[var(--shadow-pop)] p-1.5 animate-pop">
                    <div class="px-3 py-2.5 border-b border-line mb-1">
                        <div class="text-sm font-semibold text-ink truncate">{{ $user->name }}</div>
                        <div class="text-xs text-muted truncate">{{ $user->email }}</div>
                        <div class="mt-1.5"><span class="badge badge-teal">{{ $user->role?->name }}</span></div>
                    </div>
                    <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 px-3 h-9 rounded-lg text-sm text-ink-2 hover:bg-surface-2"><x-icon name="user" class="size-4"/> Profil və təhlükəsizlik</a>
                    <button type="button" @click="open = false; $dispatch('glaust:shortcuts')" class="w-full flex items-center gap-2.5 px-3 h-9 rounded-lg text-sm text-ink-2 hover:bg-surface-2"><x-icon name="keyboard" class="size-4"/> Qısa yollar <span class="ml-auto kbd">?</span></button>
                    <div class="px-3 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wider text-faint">Tema</div>
                    <div class="grid grid-cols-3 gap-1 px-1 pb-1">
                        @foreach(['light' => ['sun', 'Açıq'], 'dark' => ['moon', 'Qaranlıq'], 'system' => ['monitor', 'Sistem']] as $mode => [$icon, $label])
                            <button type="button" @click="$store.theme.set('{{ $mode }}')" class="flex flex-col items-center gap-1 py-2 rounded-lg text-[11px] font-medium transition-colors"
                                    :class="$store.theme.mode === '{{ $mode }}' ? 'bg-brand-soft text-brand-ink' : 'text-muted hover:bg-surface-2'">
                                <x-icon :name="$icon" class="size-4"/>{{ $label }}
                            </button>
                        @endforeach
                    </div>
                    <div class="border-t border-line mt-1 pt-1">
                        <form method="POST" action="{{ route('logout') }}">@csrf
                            <button class="w-full flex items-center gap-2.5 px-3 h-9 rounded-lg text-sm text-danger hover:bg-danger-soft"><x-icon name="log-out" class="size-4"/> Çıxış</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main id="main" class="page-enter px-4 sm:px-6 lg:px-8 py-6 lg:py-8 {{ $wide ? '' : 'max-w-[1500px]' }} mx-auto min-w-0">
        {{ $slot }}
    </main>
</div>

{{-- Command palette --}}
<div x-data="palette" x-cloak x-show="open" class="fixed inset-0 z-[70] flex items-start justify-center p-4 pt-[12vh]" @keydown.escape.window="open = false" role="dialog" aria-modal="true" aria-label="Komanda paneli">
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="open = false"></div>
    <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 -translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         class="relative w-full max-w-xl card !shadow-[var(--shadow-pop)] overflow-hidden" x-trap.noscroll="open">
        <div class="flex items-center gap-3 px-4 h-14 border-b border-line">
            <x-icon name="search" class="size-5 text-muted"/>
            <input x-ref="input" x-model="query" @input="search()" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="go()"
                   class="flex-1 bg-transparent outline-none text-[15px] text-ink placeholder:text-faint" placeholder="Layihə, müştəri, müqavilə, yük axtar və ya əmr yaz…" aria-label="Axtarış">
            <span x-show="searching" class="size-4 rounded-full border-2 border-brand border-t-transparent animate-spin"></span>
            <span class="kbd">Esc</span>
        </div>
        <ul x-ref="list" class="max-h-[50vh] overflow-y-auto p-2">
            <template x-for="(item, i) in results" :key="item.url + i">
                <li>
                    <a :href="item.url" @mouseenter="active = i" :data-active="active === i"
                       class="flex items-center gap-3 px-3 h-11 rounded-lg text-sm transition-colors" :class="active === i ? 'bg-brand-soft text-brand-ink' : 'text-ink-2'">
                        <span class="text-[11px] font-medium uppercase tracking-wider w-16 shrink-0 text-faint" x-text="item.group"></span>
                        <span class="truncate font-medium" x-text="item.label"></span>
                        <span class="ml-auto text-xs text-muted truncate max-w-[40%]" x-text="item.meta || ''"></span>
                        <span x-show="item.keys" class="kbd" x-text="item.keys"></span>
                    </a>
                </li>
            </template>
            <li x-show="query.length >= 2 && !searching && !results.length" class="px-3 py-8 text-center text-sm text-muted">Heç nə tapılmadı</li>
        </ul>
        <div class="flex items-center gap-4 px-4 h-10 border-t border-line bg-surface-2 text-xs text-muted">
            <span class="flex items-center gap-1"><span class="kbd">↑</span><span class="kbd">↓</span> seç</span>
            <span class="flex items-center gap-1"><span class="kbd">Enter</span> aç</span>
            <span class="ml-auto flex items-center gap-1"><span class="kbd">?</span> qısa yollar</span>
        </div>
    </div>
</div>

{{-- Keyboard shortcuts help --}}
<div x-data="shortcuts" @glaust:shortcuts.window="help = true" x-cloak x-show="help" class="fixed inset-0 z-[70] grid place-items-center p-4" role="dialog" aria-modal="true" aria-label="Klaviatura qısa yolları">
    <div x-show="help" x-transition.opacity class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="help = false"></div>
    <div x-show="help" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         class="relative w-full max-w-2xl card !shadow-[var(--shadow-pop)] p-6" x-trap.noscroll="help">
        <div class="flex items-center justify-between mb-5">
            <h2 class="text-lg font-semibold flex items-center gap-2"><x-icon name="keyboard" class="size-5 text-brand"/> Klaviatura qısa yolları</h2>
            <button type="button" class="btn btn-ghost btn-icon" @click="help = false" aria-label="Bağla"><x-icon name="x" class="size-5"/></button>
        </div>
        <div class="grid sm:grid-cols-2 gap-x-8 gap-y-1">
            <div class="flex items-center justify-between py-1.5 text-sm"><span class="text-ink-2">Axtarış / komanda paneli</span><span class="flex gap-1"><span class="kbd">Ctrl</span><span class="kbd">K</span></span></div>
            <div class="flex items-center justify-between py-1.5 text-sm"><span class="text-ink-2">Bu pəncərə</span><span class="kbd">?</span></div>
            <template x-for="s in map" :key="s.keys">
                <div class="flex items-center justify-between py-1.5 text-sm border-t border-line/60">
                    <span class="text-ink-2" x-text="s.label"></span>
                    <span class="flex gap-1"><template x-for="k in s.keys.split(' ')"><span class="kbd" x-text="k.toUpperCase()"></span></template></span>
                </div>
            </template>
        </div>
        <p class="mt-5 text-xs text-muted">Hərfləri ardıcıl basın: məsələn <span class="kbd">N</span> sonra <span class="kbd">P</span> — yeni layihə.</p>
    </div>
</div>

{{-- Confirm dialog --}}
<div x-data x-cloak x-show="$store.confirm.open" class="fixed inset-0 z-[80] grid place-items-center p-4" role="alertdialog" aria-modal="true" @keydown.escape.window="$store.confirm.cancel()">
    <div x-show="$store.confirm.open" x-transition.opacity class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="$store.confirm.cancel()"></div>
    <div x-show="$store.confirm.open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         class="relative w-full max-w-md card !shadow-[var(--shadow-pop)] p-6" x-trap="$store.confirm.open">
        <div class="flex gap-4">
            <span class="grid place-items-center size-11 shrink-0 rounded-xl bg-danger-soft text-danger"><x-icon name="alert" class="size-5"/></span>
            <div>
                <h2 class="text-base font-semibold" x-text="$store.confirm.title"></h2>
                <p class="mt-1 text-sm text-muted" x-text="$store.confirm.message"></p>
            </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn btn-secondary" @click="$store.confirm.cancel()">Ləğv et</button>
            <button type="button" class="btn btn-danger" @click="$store.confirm.accept()" x-text="$store.confirm.action"></button>
        </div>
    </div>
</div>

{{-- Toasts --}}
<div x-data class="no-print fixed bottom-4 right-4 left-4 sm:left-auto z-[90] flex flex-col items-end gap-2 pointer-events-none" aria-live="polite">
    <template x-for="t in $store.toasts.items" :key="t.id">
        <div x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-3" x-transition:enter-end="opacity-100 translate-y-0"
             class="pointer-events-auto w-full sm:w-auto sm:min-w-[300px] max-w-md card !shadow-[var(--shadow-pop)] flex items-start gap-3 px-4 py-3 animate-pop">
            <span class="mt-0.5 size-2 shrink-0 rounded-full" :class="{ 'bg-success': t.type === 'success', 'bg-danger': t.type === 'error', 'bg-saffron': t.type === 'warning', 'bg-brand': t.type === 'info' }"></span>
            <span class="text-sm text-ink flex-1" x-text="t.message"></span>
            <button type="button" class="text-faint hover:text-ink" @click="$store.toasts.remove(t.id)" aria-label="Bağla"><x-icon name="x" class="size-4"/></button>
        </div>
    </template>
</div>

<script type="application/json" id="glaust-commands">@json(Navigation::commands($user))</script>
<script type="application/json" id="glaust-shortcuts">@json(Navigation::shortcuts($user))</script>
<script type="application/json" id="glaust-flash">@json($flash)</script>
@stack('scripts')
</body>
</html>
