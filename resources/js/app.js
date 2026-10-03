import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';

window.Alpine = Alpine;
Alpine.plugin(collapse);
Alpine.plugin(focus);

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const pageData = (id, fallback = null) => {
    const el = document.getElementById(id);
    if (!el) return fallback;
    try { return JSON.parse(el.textContent); } catch { return fallback; }
};

/**
 * JSON helper. Server AJAX endpoints answer HTTP 200 with {ok:false, message} for
 * business errors because the hosting CDN strips bodies from 4xx responses.
 */
async function api(url, { method = 'GET', body = null } = {}) {
    const res = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : null,
        credentials: 'same-origin',
    });
    if (res.status === 401 || res.status === 419) {
        window.location.reload();
        throw new Error('Sessiya bitib');
    }
    let data = null;
    try { data = await res.json(); } catch { /* empty body */ }
    if (!res.ok || !data) throw new Error(data?.message || 'Server xətası (' + res.status + ')');
    return data;
}
window.glaustApi = api;

// localStorage can throw (private mode, blocked storage); Alpine expressions cannot hold try/catch.
window.glaustPref = {
    get(key) { try { return localStorage.getItem(key); } catch { return null; } },
    set(key, value) { try { localStorage.setItem(key, value); } catch { /* ignore */ } },
};

// Same output as the PHP num() helper: "1 234 567,50" (NBSP grouping, comma decimals),
// independent of the browser's Intl locale data.
const fmt = (value, decimals = 2) => {
    const n = Number(value) || 0;
    const [int, frac] = Math.abs(n).toFixed(decimals).split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (n < 0 && Number(Math.abs(n).toFixed(decimals)) !== 0 ? '-' : '') + grouped + (frac ? ',' + frac : '');
};

const fmtRate = (rate) => {
    const s = Number(rate).toFixed(8).replace(/0+$/, '');
    const [i, f = ''] = s.split('.');
    return i + ',' + f.padEnd(4, '0');
};
window.glaustFmt = { fmt, fmtRate };

/* ---------- theme ---------- */
Alpine.store('theme', {
    mode: document.documentElement.dataset.theme || 'system',
    get dark() { return document.documentElement.classList.contains('dark'); },
    set(mode) {
        this.mode = mode;
        try { localStorage.setItem('glaust-theme', mode); } catch { /* private mode */ }
        applyTheme(mode);
        api('/profile/theme', { method: 'POST', body: { theme: mode } }).catch(() => {});
    },
});
function applyTheme(mode) {
    const dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.dataset.theme = mode;
    window.dispatchEvent(new CustomEvent('glaust:theme', { detail: { dark } }));
}
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (Alpine.store('theme').mode === 'system') applyTheme('system');
});

/* ---------- toasts ---------- */
Alpine.store('toasts', {
    items: [],
    push(type, message, timeout = 4500) {
        const id = Date.now() + Math.random();
        this.items.push({ id, type, message });
        setTimeout(() => this.remove(id), timeout);
    },
    remove(id) { this.items = this.items.filter((t) => t.id !== id); },
});
window.glaustApi = api;
window.toast = (type, message) => Alpine.store('toasts').push(type, message);

/* ---------- confirm dialog for destructive forms ---------- */
Alpine.store('confirm', {
    open: false, title: '', message: '', action: 'Sil', form: null,
    ask(form) {
        this.form = form;
        this.title = form.dataset.confirmTitle || 'Əminsiniz?';
        this.message = form.dataset.confirm || 'Bu əməliyyat geri qaytarıla bilməz.';
        this.action = form.dataset.confirmAction || 'Sil';
        this.open = true;
    },
    accept() { const f = this.form; this.open = false; this.form = null; if (f) { f.dataset.confirmed = '1'; f.requestSubmit ? f.requestSubmit() : f.submit(); } },
    cancel() { this.open = false; this.form = null; },
});
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form instanceof HTMLFormElement && form.dataset.confirm !== undefined && form.dataset.confirmed !== '1') {
        e.preventDefault();
        Alpine.store('confirm').ask(form);
    }
}, true);

/* ---------- currency ticker (header) ---------- */
Alpine.data('ticker', () => ({
    loading: true, ok: false, stale: false, date: null, items: [], error: null, focus: 0,
    async init() {
        await this.load();
        setInterval(() => this.load(), 30 * 60 * 1000);
        // Mobile: one currency at a time, flipping every 3.5 s.
        setInterval(() => { if (this.items.length) this.focus = (this.focus + 1) % this.items.length; }, 3500);
    },
    async load() {
        try {
            const d = await api('/ajax/ticker');
            Object.assign(this, { ok: d.ok, stale: d.stale, date: d.date, items: d.items || [], error: d.ok ? null : d.message });
        } catch (e) {
            this.ok = false; this.error = e.message;
        } finally { this.loading = false; }
    },
    get duration() { return Math.max(24, this.items.length * 7) + 's'; },
    get dateLabel() { if (!this.date) return ''; const [y, m, d] = this.date.split('-'); return `${d}.${m}.${y}`; },
    rate: (r) => fmtRate(r),
    change: (c) => (c === null ? '—' : (c > 0 ? '+' : '') + fmt(c, 2) + '%'),
}));

/* ---------- my day: today's tasks + reminders (header) ---------- */
Alpine.data('myDay', () => ({
    open: false, tab: 'tasks', loading: true, ringing: false, lastReminderIds: null,
    tasks: { overdue: [], today: [], upcoming: [] }, reminders: [], counts: { tasks: 0, reminders: 0, overdue: 0 },
    async init() {
        await this.load();
        setInterval(() => this.load(), 60 * 1000);
    },
    async load() {
        try {
            const d = await api('/ajax/my-day');
            const ids = d.reminders.map((r) => r.id);
            if (this.lastReminderIds && ids.some((id) => !this.lastReminderIds.includes(id))) this.ring();
            this.lastReminderIds = ids;
            Object.assign(this, { tasks: d.tasks, reminders: d.reminders, counts: d.counts });
        } catch { /* keep last state */ } finally { this.loading = false; }
    },
    ring() { if (reducedMotion()) return; this.ringing = false; requestAnimationFrame(() => { this.ringing = true; setTimeout(() => (this.ringing = false), 1900); }); },
    toggle(tab) { if (this.open && this.tab === tab) { this.open = false; return; } this.tab = tab; this.open = true; },
    async complete(task) {
        task.done = true;
        try { await api(`/ajax/tasks/${task.id}/complete`, { method: 'POST' }); toast('success', 'Tapşırıq tamamlandı'); setTimeout(() => this.load(), 450); }
        catch (e) { task.done = false; toast('error', e.message); }
    },
    async read(r) {
        this.reminders = this.reminders.filter((x) => x.id !== r.id);
        this.counts.reminders = Math.max(0, this.counts.reminders - 1);
        try { await api(`/ajax/reminders/${r.id}/read`, { method: 'POST' }); } catch (e) { toast('error', e.message); this.load(); }
    },
    async snooze(r, minutes) {
        this.reminders = this.reminders.filter((x) => x.id !== r.id);
        this.counts.reminders = Math.max(0, this.counts.reminders - 1);
        try { await api(`/ajax/reminders/${r.id}/snooze`, { method: 'POST', body: { minutes } }); toast('info', 'Xatırlatma ertələndi'); }
        catch (e) { toast('error', e.message); this.load(); }
    },
    get taskTotal() { return this.counts.tasks; },
}));

/* ---------- command palette (Ctrl+K) ---------- */
Alpine.data('palette', () => ({
    open: false, query: '', active: 0, remote: [], searching: false, timer: null,
    commands: pageData('glaust-commands', []),
    init() {
        window.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); this.show(); }
        });
        window.addEventListener('glaust:palette', () => this.show());
    },
    show() { this.open = true; this.query = ''; this.remote = []; this.active = 0; this.$nextTick(() => this.$refs.input?.focus()); },
    norm: (s) => s.toLocaleLowerCase('az').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ı/g, 'i').replace(/ə/g, 'e'),
    get local() {
        const q = this.norm(this.query.trim());
        const list = q ? this.commands.filter((c) => this.norm(c.label + ' ' + (c.keywords || '')).includes(q)) : this.commands;
        return list.slice(0, q ? 8 : 12);
    },
    get results() { return [...this.local, ...this.remote]; },
    search() {
        clearTimeout(this.timer);
        this.active = 0;
        if (this.query.trim().length < 2) { this.remote = []; return; }
        this.timer = setTimeout(async () => {
            this.searching = true;
            try { this.remote = (await api('/ajax/search?q=' + encodeURIComponent(this.query.trim()))).results; }
            catch { this.remote = []; } finally { this.searching = false; }
        }, 220);
    },
    move(d) { const n = this.results.length; if (n) this.active = (this.active + d + n) % n; this.$nextTick(() => this.$refs.list?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' })); },
    go(item = this.results[this.active]) { if (item?.url) window.location.href = item.url; },
}));

/* ---------- keyboard shortcuts: "g d", "n p", "?" ---------- */
Alpine.data('shortcuts', () => ({
    help: false, buffer: '', timer: null,
    map: pageData('glaust-shortcuts', []),
    init() {
        window.addEventListener('keydown', (e) => {
            const t = e.target;
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            if (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName)) return;
            if (e.key === '?') { e.preventDefault(); this.help = !this.help; return; }
            if (e.key === 'Escape') { this.help = false; return; }
            if (e.key === '/') { e.preventDefault(); window.dispatchEvent(new Event('glaust:palette')); return; }
            if (e.key.length !== 1) return;
            this.buffer = (this.buffer + ' ' + e.key.toLowerCase()).trim().split(' ').slice(-2).join(' ');
            clearTimeout(this.timer);
            this.timer = setTimeout(() => (this.buffer = ''), 900);
            const hit = this.map.find((s) => s.keys === this.buffer);
            if (hit) { this.buffer = ''; window.location.href = hit.url; }
        });
    },
}));

/* ---------- animated number: x-countup="1234.5" x-countup:decimals="2" ---------- */
Alpine.directive('countup', (el, { expression, modifiers }, { evaluate }) => {
    const target = Number(evaluate(expression)) || 0;
    const decimals = modifiers.includes('int') ? 0 : Number(el.dataset.decimals ?? 2);
    const suffix = el.dataset.suffix ?? '';
    const render = (v) => { el.textContent = fmt(v, decimals) + suffix; };
    if (reducedMotion()) { render(target); return; }
    const start = performance.now();
    const dur = 900;
    const tick = (now) => {
        const p = Math.min(1, (now - start) / dur);
        render(target * (1 - Math.pow(1 - p, 3)));
        if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
});

/* ---------- charts: <div x-chart='{"type":"bar", ...}'> (ApexCharts, lazy-loaded) ---------- */
const palette = ['#0f9d8a', '#6366f1', '#e9a23b', '#e5484d', '#64748b', '#8b5cf6', '#0ea5e9', '#84cc16'];
function themed(config) {
    const dark = document.documentElement.classList.contains('dark');
    const css = getComputedStyle(document.documentElement);
    const muted = css.getPropertyValue('--g-muted').trim();
    const line = css.getPropertyValue('--g-line').trim();
    const money = config.money;
    const yFmt = (v) => (v === null || v === undefined ? '' : money ? fmt(v, 0) + ' ₼' : fmt(v, config.decimals ?? 0));
    return {
        colors: config.colors || palette,
        ...config.options,
        chart: {
            type: config.type, height: config.height || 300, fontFamily: 'IBM Plex Sans, sans-serif', foreColor: muted,
            toolbar: { show: false }, zoom: { enabled: false }, background: 'transparent',
            animations: { enabled: !reducedMotion(), speed: 650, animateGradually: { enabled: true, delay: 90 } },
            stacked: config.stacked || false,
            sparkline: { enabled: !!config.sparkline },
        },
        theme: { mode: dark ? 'dark' : 'light' },
        series: config.series,
        labels: config.labels,
        xaxis: { categories: config.categories, axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: { fontSize: '12px' } }, ...(config.xaxis || {}) },
        yaxis: config.yaxis || { labels: { formatter: yFmt, style: { fontSize: '12px' } } },
        grid: { borderColor: line, strokeDashArray: 4, padding: { left: 6, right: 6 } },
        dataLabels: { enabled: false },
        legend: { position: 'bottom', fontSize: '13px', markers: { size: 6, shape: 'circle' }, itemMargin: { horizontal: 10, vertical: 4 } },
        stroke: config.stroke || (config.type === 'area' || config.type === 'line' ? { curve: 'smooth', width: 2.5 } : { width: config.type === 'donut' ? 2 : 0, colors: config.type === 'donut' ? [css.getPropertyValue('--g-surface').trim()] : undefined }),
        fill: config.type === 'area' ? { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.32, opacityTo: 0.02, stops: [0, 95] } } : { opacity: 1 },
        plotOptions: {
            bar: { borderRadius: 5, borderRadiusApplication: 'end', columnWidth: config.columnWidth || '52%', horizontal: !!config.horizontal, barHeight: '62%' },
            pie: { donut: { size: '72%', labels: { show: true, name: { fontSize: '13px' }, value: { fontSize: '22px', fontWeight: 600, color: css.getPropertyValue('--g-ink').trim(), formatter: (v) => (money ? fmt(Number(v), 0) + ' ₼' : v) }, total: { show: true, label: config.totalLabel || 'Cəmi', color: muted, formatter: (w) => { const s = w.globals.seriesTotals.reduce((a, b) => a + b, 0); return money ? fmt(s, 0) + ' ₼' : s; } } } } },
        },
        tooltip: { theme: dark ? 'dark' : 'light', y: { formatter: (v) => (money ? fmt(v, 2) + ' ₼' : config.rate ? fmtRate(v) + ' ₼' : fmt(v, config.decimals ?? 0)) } },
        markers: { size: config.type === 'line' ? 0 : 0, hover: { size: 5 } },
        noData: { text: 'Məlumat yoxdur' },
    };
}
Alpine.directive('chart', (el, { expression }, { evaluate, cleanup }) => {
    const config = evaluate(expression);
    let chart = null;
    let disposed = false;
    const draw = async () => {
        const { default: ApexCharts } = await import('apexcharts');
        if (disposed) return;
        chart?.destroy();
        el.innerHTML = '';
        chart = new ApexCharts(el, themed(config));
        chart.render();
    };
    const io = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) { io.disconnect(); draw(); }
    }, { rootMargin: '120px' });
    io.observe(el);
    // Elements already on screen are drawn even if the observer never fires (headless / print).
    setTimeout(() => { if (!chart) { io.disconnect(); draw(); } }, 1200);
    const onTheme = () => chart && draw();
    window.addEventListener('glaust:theme', onTheme);
    cleanup(() => { disposed = true; io.disconnect(); window.removeEventListener('glaust:theme', onTheme); chart?.destroy(); });
});

/* ---------- searchable remote select ---------- */
// cfg.depends: name of another combobox (the party). Its value filters this list
// (&counterparty_id=...); picking an item that carries party_id fills that combobox;
// switching the party to someone else clears a now-foreign selection.
Alpine.data('combobox', (cfg) => ({
    open: false, query: '', items: [], active: 0, loading: false, timer: null,
    value: cfg.value ?? '', label: cfg.label ?? '', partyId: cfg.partyId ?? null,
    init() {
        this.$watch('open', (o) => { if (o) { this.query = ''; this.fetch(); this.$nextTick(() => this.$refs.q?.focus()); } });
        window.addEventListener('combobox:set:' + cfg.name, (e) => this.pick(e.detail));
        if (cfg.depends) {
            window.addEventListener('combobox-change', (e) => {
                if (e.detail.name !== cfg.depends || !this.value) return;
                const party = e.detail.item?.id ?? null;
                if (party !== null && this.partyId !== null && String(party) !== String(this.partyId)) this.pick(null);
            });
        }
    },
    dependsValue() {
        return cfg.depends ? (document.querySelector(`input[type=hidden][name="${cfg.depends}"]`)?.value || '') : '';
    },
    fetch() {
        clearTimeout(this.timer);
        this.timer = setTimeout(async () => {
            this.loading = true;
            try {
                const sep = cfg.url.includes('?') ? '&' : '?';
                const party = this.dependsValue();
                this.items = (await api(cfg.url + sep + 'q=' + encodeURIComponent(this.query) + (party ? '&counterparty_id=' + encodeURIComponent(party) : ''))).results;
                this.active = 0;
            } catch { this.items = []; } finally { this.loading = false; }
        }, 180);
    },
    pick(item) {
        this.value = item ? item.id : ''; this.label = item ? item.label : ''; this.open = false;
        this.partyId = item?.party_id ?? null;
        this.$dispatch('combobox-change', { name: cfg.name, item });
        if (cfg.depends && item?.party_id && String(this.dependsValue()) !== String(item.party_id)) {
            window.dispatchEvent(new CustomEvent('combobox:set:' + cfg.depends, { detail: { id: item.party_id, label: item.party, type: item.party_type } }));
        }
    },
    move(d) { const n = this.items.length; if (n) this.active = (this.active + d + n) % n; },
}));

/* ---------- official rate lookup for money forms ---------- */
Alpine.data('rateLookup', (cfg) => ({
    currency: cfg.currency || 'AZN', date: cfg.date || '', amount: cfg.amount || '',
    cbar: cfg.cbar || null, applied: cfg.applied || '', error: null, loading: false, override: !!cfg.override,
    init() {
        this.$watch('currency', () => this.lookup());
        this.$watch('date', () => this.lookup());
        if (!this.cbar) this.lookup();
    },
    async lookup() {
        this.error = null;
        if (this.currency === 'AZN') { this.cbar = 1; if (!this.override) this.applied = 1; return; }
        if (!this.date) return;
        this.loading = true;
        try {
            const d = await api(`/ajax/rate?currency=${encodeURIComponent(this.currency)}&date=${encodeURIComponent(this.date)}`);
            if (d.ok) { this.cbar = d.rate; if (!this.override) this.applied = d.rate; } else { this.cbar = null; this.error = d.message; }
        } catch (e) { this.cbar = null; this.error = e.message; } finally { this.loading = false; }
    },
    get appliedRate() { return this.override ? parseFloat(String(this.applied).replace(',', '.')) || 0 : (this.cbar || 0); },
    get azn() { const a = parseFloat(String(this.amount).replace(/\s/g, '').replace(',', '.')) || 0; return a * this.appliedRate; },
    get cbarAzn() { const a = parseFloat(String(this.amount).replace(/\s/g, '').replace(',', '.')) || 0; return a * (this.cbar || 0); },
    money: (v) => fmt(v, 2),
    rate: (r) => (r ? fmtRate(r) : '—'),
}));

/* ---------- kanban (SortableJS, lazy-loaded) ---------- */
Alpine.data('kanban', (moveUrl) => ({
    async init() {
        const { default: Sortable } = await import('sortablejs');
        this.$el.querySelectorAll('[data-column]').forEach((col) => {
            Sortable.create(col, {
                group: 'kanban', animation: reducedMotion() ? 0 : 180, ghostClass: 'opacity-40', dragClass: 'rotate-1',
                onEnd: async (evt) => {
                    const id = evt.item.dataset.id;
                    const status = evt.to.dataset.column;
                    const order = [...evt.to.querySelectorAll('[data-id]')].map((n) => n.dataset.id);
                    this.recount();
                    try { await api(moveUrl.replace('__ID__', id), { method: 'POST', body: { status, order } }); }
                    catch (e) { toast('error', e.message); setTimeout(() => window.location.reload(), 900); }
                },
            });
        });
    },
    recount() { this.$el.querySelectorAll('[data-count-for]').forEach((c) => { c.textContent = this.$el.querySelector(`[data-column="${c.dataset.countFor}"]`).querySelectorAll('[data-id]').length; }); },
}));

/* ---------- dashboard widget layout (hide / reorder) ---------- */
Alpine.data('dashboardLayout', (initial, all) => ({
    editing: false, order: initial.order, hidden: initial.hidden, all,
    visible(key) { return !this.hidden.includes(key); },
    toggle(key) { this.hidden = this.visible(key) ? [...this.hidden, key] : this.hidden.filter((k) => k !== key); },
    move(key, d) {
        const i = this.order.indexOf(key); const j = i + d;
        if (i < 0 || j < 0 || j >= this.order.length) return;
        const o = [...this.order]; [o[i], o[j]] = [o[j], o[i]]; this.order = o;
    },
    pos(key) { return this.order.indexOf(key); },
    async save() {
        this.editing = false;
        try { await api('/dashboard/layout', { method: 'POST', body: { order: this.order, hidden: this.hidden } }); toast('success', 'Panel yadda saxlanıldı'); }
        catch (e) { toast('error', e.message); }
    },
}));

/* ---------- small helpers ---------- */
Alpine.data('repeater', (rows, blank) => ({
    rows: rows.length ? rows : [],
    add() { this.rows.push({ ...blank, _k: Date.now() + Math.random() }); },
    remove(i) { this.rows.splice(i, 1); },
}));

document.addEventListener('alpine:init', () => {});
Alpine.start();

// Flash messages rendered by the server.
for (const f of pageData('glaust-flash', [])) toast(f.type, f.message);
