@props(['title' => null, 'heading' => null, 'subheading' => null])
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}Glaust Management System</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <script nonce="{{ Vite::cspNonce() }}">
        (() => {
            let mode = 'system';
            try { mode = localStorage.getItem('glaust-theme') || mode; } catch (e) {}
            const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh grid lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
    {{-- Brand panel --}}
    <aside class="relative hidden lg:flex flex-col justify-between overflow-hidden bg-night text-slate-300 p-12">
        <div class="absolute inset-0 opacity-[.35] [background-image:linear-gradient(#1f2943_1px,transparent_1px),linear-gradient(90deg,#1f2943_1px,transparent_1px)] [background-size:44px_44px] [mask-image:radial-gradient(ellipse_at_30%_40%,#000_20%,transparent_75%)]"></div>
        <div class="absolute -top-40 -left-24 size-[520px] rounded-full bg-teal-500/20 blur-3xl"></div>
        <div class="absolute bottom-[-180px] right-[-120px] size-[460px] rounded-full bg-amber-400/10 blur-3xl"></div>

        <div class="relative flex items-center gap-3">
            <span class="grid place-items-center size-10 rounded-xl bg-gradient-to-br from-teal-400 to-teal-700 text-night shadow-[0_0_30px_-4px_#2dd4bf]">
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M17 7.5A6.5 6.5 0 1 0 18.5 13H12"/></svg>
            </span>
            <span class="text-lg font-semibold text-white tracking-tight">Glaust <span class="text-slate-500 font-normal">Management System</span></span>
        </div>

        <div class="relative max-w-lg stagger">
            <p class="text-sm font-medium text-teal-300 tracking-wide" style="--i:0">Layihə · CRM · Müqavilə · Bank · Logistika</p>
            <h2 class="mt-4 text-4xl xl:text-[44px] leading-[1.1] font-semibold text-white tracking-tight" style="--i:1">
                Şirkətinizin bütün işi<br>bir idarə panelində.
            </h2>
            <p class="mt-5 text-[15px] leading-relaxed text-slate-400" style="--i:2">
                Mərkəzi Bankın rəsmi məzənnəsi ilə hesablanan bank əməliyyatları, müştəri və təchizatçılarla bağlanan müqavilələr,
                yüklərin izlənməsi və hər səhər mailinizə gələn gündəlik iş siyahısı.
            </p>
            <div class="mt-10 grid grid-cols-3 gap-3" style="--i:3">
                @foreach([['coins', 'CBAR məzənnələri', 'hər gün avtomatik'], ['bell', 'Xatırlatmalar', 'panel və mail'], ['shield', 'Rollar və loglar', 'hər giriş qeydə alınır']] as [$i, $t, $s])
                    <div class="rounded-xl border border-white/[0.07] bg-white/[0.03] p-4">
                        <x-icon :name="$i" class="size-5 text-teal-300"/>
                        <div class="mt-3 text-sm font-medium text-white">{{ $t }}</div>
                        <div class="text-xs text-slate-500 mt-0.5">{{ $s }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <p class="relative text-xs text-slate-600">© {{ date('Y') }} Glaust MS · Bütün hüquqlar qorunur</p>
    </aside>

    {{-- Form panel --}}
    <main class="flex flex-col justify-center px-5 py-10 sm:px-10 lg:px-16 bg-canvas">
        <div class="w-full max-w-[420px] mx-auto rise">
            <div class="lg:hidden flex items-center gap-2.5 mb-10">
                <span class="grid place-items-center size-9 rounded-xl bg-gradient-to-br from-teal-400 to-teal-700 text-night">
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M17 7.5A6.5 6.5 0 1 0 18.5 13H12"/></svg>
                </span>
                <span class="text-base font-semibold tracking-tight">Glaust MS</span>
            </div>
            @if($heading)
                <h1 class="text-[26px] font-semibold tracking-tight">{{ $heading }}</h1>
            @endif
            @if($subheading)
                <p class="mt-2 text-sm text-muted">{{ $subheading }}</p>
            @endif
            @if(session('status'))
                <div class="mt-6 flex gap-2.5 rounded-xl border border-brand/30 bg-brand-soft px-4 py-3 text-sm text-brand-ink" role="status">
                    <x-icon name="check-circle" class="size-5 shrink-0"/> {{ session('status') }}
                </div>
            @endif
            <div class="mt-8">{{ $slot }}</div>
        </div>
    </main>
</body>
</html>
