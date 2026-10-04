@props(['title' => null])
@php
    $flash = collect(['success', 'error', 'info', 'warning'])->filter(fn ($k) => session()->has($k))->map(fn ($k) => ['type' => $k, 'message' => session($k)])->values();
    $links = [['admin.dashboard', 'dashboard', __('Ümumi baxış')], ['admin.companies.index', 'building', __('Şirkətlər')], ['admin.plans.index', 'layers', __('Tariflər')], ['admin.logs', 'history', __('Sistem logları')]];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="{{ \App\Support\Csp::policy(true) }}"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}TradeFlow Admin</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <script nonce="{{ Vite::cspNonce() }}">(() => { let m = 'system'; try { m = localStorage.getItem('glaust-theme') || m } catch (e) {} document.documentElement.classList.toggle('dark', m === 'dark' || (m === 'system' && matchMedia('(prefers-color-scheme: dark)').matches)); })();</script>
    <script nonce="{{ Vite::cspNonce() }}">window.__i18n = @json(\App\Support\JsTranslations::all());</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data class="overflow-x-hidden">
    <header class="sticky top-0 z-30 bg-night text-slate-300 border-b border-night-line">
        <div class="max-w-[1400px] mx-auto h-16 px-4 sm:px-6 flex items-center gap-6">
            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2.5 shrink-0">
                <span class="grid place-items-center size-8 rounded-lg bg-gradient-to-br from-amber-300 to-amber-600 text-night"><x-icon name="crown" class="size-4"/></span>
                <span class="font-semibold text-white">{{ __('TradeFlow') }} <span class="text-slate-500 font-normal">{{ __('Platforma') }}</span></span>
            </a>
            <nav class="flex gap-1 overflow-x-auto">
                @foreach($links as [$r, $i, $l])
                    <a href="{{ route($r) }}" @class(['nav-link !h-9 whitespace-nowrap', 'is-active' => request()->routeIs($r.'*')])><x-icon :name="$i" class="size-4"/> {{ $l }}</a>
                @endforeach
            </nav>
            @if(auth()->user()?->company_id)
                <a href="{{ route('dashboard') }}" class="nav-link !h-9 ml-auto"><x-icon name="arrow-left" class="size-4"/> <span class="hidden sm:inline">{{ __('Şirkətə qayıt') }}</span></a>
            @endif
            <x-locale-switcher compact @class(['w-32', 'ml-auto' => ! auth()->user()?->company_id])/>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-link !h-9"><x-icon name="log-out" class="size-4"/> <span class="hidden sm:inline">{{ __('Çıxış') }}</span></button></form>
        </div>
    </header>
    <main class="page-enter max-w-[1400px] mx-auto px-4 sm:px-6 py-8">{{ $slot }}</main>
    <div class="fixed bottom-4 right-4 left-4 sm:left-auto z-[90] flex flex-col items-end gap-2 pointer-events-none" aria-live="polite">
        <template x-for="t in $store.toasts.items" :key="t.id">
            <div class="pointer-events-auto card !shadow-[var(--shadow-pop)] flex items-start gap-3 px-4 py-3 animate-pop max-w-md">
                <span class="mt-1.5 size-2 rounded-full" :class="{ 'bg-success': t.type === 'success', 'bg-danger': t.type === 'error', 'bg-saffron': t.type === 'warning', 'bg-brand': t.type === 'info' }"></span>
                <span class="text-sm" x-text="t.message"></span>
            </div>
        </template>
    </div>
    <div x-data x-cloak x-show="$store.confirm.open" class="fixed inset-0 z-[80] grid place-items-center p-4" role="alertdialog" aria-modal="true">
        <div class="absolute inset-0 bg-night/50" @click="$store.confirm.cancel()"></div>
        <div class="relative w-full max-w-md card p-6"><h2 class="font-semibold" x-text="$store.confirm.title"></h2><p class="mt-1 text-sm text-muted" x-text="$store.confirm.message"></p>
            <div class="mt-6 flex justify-end gap-2"><button class="btn btn-secondary" @click="$store.confirm.cancel()">{{ __('Ləğv et') }}</button><button class="btn btn-danger" @click="$store.confirm.accept()" x-text="$store.confirm.action"></button></div></div>
    </div>
    <script type="application/json" id="glaust-flash">@json($flash)</script>
</body>
</html>
