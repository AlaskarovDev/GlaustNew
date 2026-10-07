<x-layouts.app :title="__('Toplu Trade importu')" wide>
    <x-page-header :title="__('Toplu Trade importu — yoxlama')" icon="layers" :back="route('imports.atf.create')"
                   :subtitle="$job['file'].' · '.count($rows).' '.__('sətir').' · '.($project?->code.' '.$project?->name)"/>

    @php
        $s = $job['setup'];
        $ready = collect($rows)->filter(fn ($r) => ! $r['errors'] && ! $r['exists'])->count();
        $state = collect($rows)->map(fn ($r, $i) => ['i' => $i, 'status' => $r['errors'] ? 'error' : ($r['exists'] ? 'exists' : 'ready'), 'message' => $r['errors'] ? implode('; ', $r['errors']) : '', 'url' => null, 'steps' => []])->values();
        $acc = fn ($id) => ($a = $accounts[$id] ?? null) ? $a->name.' · '.$a->currency : '—';
    @endphp

    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        <div class="card p-4"><div class="text-xs text-muted">{{ __('Layihə') }}</div><div class="font-semibold mt-0.5">{{ $project?->name }}</div><div class="text-[11px] text-faint font-mono">{{ $project?->code }}</div></div>
        <div class="card p-4"><div class="text-xs text-muted">{{ __('Satıcı → alıcı') }}</div><div class="font-semibold mt-0.5">{{ $parties[$s['supplier_id']] ?? '—' }} → {{ $parties[$s['buyer_id']] ?? '—' }}</div>
            <div class="text-[11px] text-faint">{{ __('Logistika') }}: {{ $parties[$s['logistics_id'] ?? 0] ?? '—' }}</div></div>
        <div class="card p-4"><div class="text-xs text-muted">{{ __('Hesablar') }}</div><div class="text-[12px] mt-0.5 space-y-0.5"><div>{{ $acc($s['rub_account']) }}</div><div>{{ $acc($s['azn_account']) }}</div><div>{{ $acc($s['eur_account']) }}</div></div></div>
        <div class="card p-4"><div class="text-xs text-muted">{{ __('Import ediləcək') }}</div><div class="text-2xl font-semibold font-mono mt-0.5">{{ $ready }} <span class="text-sm text-muted font-sans">/ {{ count($rows) }}</span></div>
            <div class="text-[11px] text-faint">{{ __('qalanı: artıq var və ya xətalıdır') }}</div></div>
    </div>

    <section class="card overflow-hidden" x-data="{
            rows: @js($state), running: false, done: false, current: null,
            get todo() { return this.rows.filter(r => r.status === 'ready'); },
            count(s) { return this.rows.filter(r => r.status === s).length; },
            get progress() { const t = this.rows.filter(r => r.wasReady).length || 1; return Math.round(this.rows.filter(r => r.wasReady && ['done', 'failed', 'skipped'].includes(r.status)).length / t * 100); },
            async run() {
                this.running = true;
                this.rows.forEach(r => { if (r.status === 'ready') r.wasReady = true; });
                for (const r of this.rows) {
                    if (r.status !== 'ready') continue;
                    r.status = 'running'; this.current = r.i;
                    try {
                        const d = await glaustApi(@js(route('imports.atf.row', [$token, '__N__'])).replace('__N__', r.i), { method: 'POST' });
                        r.status = d.ok ? (d.skipped ? 'skipped' : 'done') : 'failed';
                        r.message = d.message || ''; r.url = d.url || null; r.steps = d.steps || []; r.code = d.code || '';
                    } catch (e) { r.status = 'failed'; r.message = e.message; }
                }
                this.running = false; this.done = true; this.current = null;
            },
         }">
        <header class="px-5 py-4 border-b border-line flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">{{ __('Sətirlər') }}</h2>
                <p class="text-xs text-muted">{{ __('Hər sətir ayrıca, tam işlənir: alınmayan sətir heç nə yaratmır, digərlərinə təsir etmir.') }}</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex gap-1.5 text-[11px]">
                    <span class="badge badge-teal" x-show="count('ready')"><span x-text="count('ready')"></span> {{ __('hazır') }}</span>
                    <span class="badge badge-green" x-show="count('done')"><span x-text="count('done')"></span> {{ __('yaradıldı') }}</span>
                    <span class="badge badge-slate" x-show="count('exists') + count('skipped')"><span x-text="count('exists') + count('skipped')"></span> {{ __('artıq var') }}</span>
                    <span class="badge badge-rose" x-show="count('error') + count('failed')"><span x-text="count('error') + count('failed')"></span> {{ __('xəta') }}</span>
                </div>
                <button type="button" class="btn btn-primary" @click="run()" :disabled="running || !todo.length" x-show="!done || todo.length">
                    <x-icon name="upload" class="size-4"/> <span x-text="running ? {{ \Illuminate\Support\Js::from(__('İşlənir…')) }} : {{ \Illuminate\Support\Js::from(__('İmportu başlat')) }} + ' (' + todo.length + ')'"></span>
                </button>
                <a href="{{ route('projects.show', $s['project_id']) }}" class="btn btn-secondary" x-show="done" x-cloak><x-icon name="folder" class="size-4"/> {{ __('Layihəyə keç') }}</a>
            </div>
        </header>
        <div class="h-1.5 bg-surface-2" x-show="running || done" x-cloak><div class="h-full bg-brand transition-all" :style="`width: ${progress}%`"></div></div>

        <div class="overflow-x-auto">
            <table class="table-g text-[13px]">
                <thead><tr>
                    <th>#</th><th>{{ __('Satıcı fakturası') }}</th><th class="!text-right">{{ __('Məbləğ') }}</th><th>{{ __('Alıcı fakturası') }}</th><th class="!text-right">{{ __('Məbləğ') }}</th>
                    <th>{{ __('Mədaxil') }}</th><th>{{ __('Əməliyyat') }}</th><th class="!text-right">{{ __('RUB satılır / EUR alınır') }}</th><th class="!text-right">{{ __('Logistika') }}</th><th>{{ __('Akt') }}</th><th class="min-w-[260px]">{{ __('Vəziyyət') }}</th>
                </tr></thead>
                <tbody>
                @foreach($rows as $i => $r)
                    <tr :class="rows[{{ $i }}].status === 'running' && 'bg-brand-soft/40'">
                        <td class="font-mono text-xs text-muted">{{ $r['order'] ?? $r['line'] }}</td>
                        <td><span class="font-mono font-medium">{{ $r['seller_no'] }}</span><div class="text-[11px] text-muted">{{ $r['seller_date'] ? azdate($r['seller_date']) : '—' }}</div></td>
                        <td class="num">{{ $r['seller_amount'] !== null ? money($r['seller_amount'], 'EUR') : '—' }}</td>
                        <td><span class="font-mono">{{ $r['buyer_no'] ?? '—' }}</span><div class="text-[11px] text-muted">{{ $r['buyer_date'] ? azdate($r['buyer_date']) : '' }}</div></td>
                        <td class="num">{{ $r['buyer_amount'] !== null ? money($r['buyer_amount'], 'RUB') : '—' }}</td>
                        <td class="font-mono text-xs">{{ $r['money_in_date'] ? azdate($r['money_in_date']) : '—' }}</td>
                        <td class="font-mono text-xs">{{ ($r['operation_date'] ?? $r['seller_paid_date']) ? azdate($r['operation_date'] ?? $r['seller_paid_date']) : '—' }}</td>
                        <td class="num text-xs">{{ $r['rub_sold'] ? money($r['rub_sold'], 'RUB').' @ '.rate_fmt($r['bank_rub']) : '—' }}<div class="text-muted">{{ $r['eur_bought'] ? money($r['eur_bought'], 'EUR').' @ '.rate_fmt($r['bank_eur']) : '' }}</div></td>
                        <td class="num text-xs">{{ $r['logistics_paid'] ? money($r['logistics_paid'], 'RUB') : '—' }}<div class="text-muted">{{ $r['logistics_date'] ? azdate($r['logistics_date']) : '' }}</div></td>
                        <td class="text-xs"><span class="font-mono">{{ $r['act_no'] ?? '—' }}</span><div class="text-muted">{{ $r['act_date'] ? azdate($r['act_date']) : '' }}</div></td>
                        <td class="text-xs">
                            <template x-if="rows[{{ $i }}].status === 'ready'"><span class="badge badge-teal">{{ __('Hazır') }}</span></template>
                            <template x-if="rows[{{ $i }}].status === 'running'"><span class="badge badge-amber">{{ __('İşlənir…') }}</span></template>
                            <template x-if="rows[{{ $i }}].status === 'exists' || rows[{{ $i }}].status === 'skipped'"><span class="badge badge-slate">{{ __('Artıq var') }}</span></template>
                            <template x-if="rows[{{ $i }}].status === 'done'"><span><a :href="rows[{{ $i }}].url" class="badge badge-green hover:underline" x-text="'✓ ' + rows[{{ $i }}].code"></a>
                                <span class="block mt-1 text-[11px] text-muted" x-text="rows[{{ $i }}].steps.join(' · ')"></span></span></template>
                            <template x-if="rows[{{ $i }}].status === 'error' || rows[{{ $i }}].status === 'failed'"><span><span class="badge badge-rose">{{ __('Xəta') }}</span>
                                <span class="block mt-1 text-[11px] text-danger" x-text="rows[{{ $i }}].message"></span></span></template>
                            @if($r['notes'])
                                <ul class="mt-1 space-y-0.5 text-[11px] text-muted">@foreach($r['notes'] as $note)<li>· {{ $note }}</li>@endforeach</ul>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
