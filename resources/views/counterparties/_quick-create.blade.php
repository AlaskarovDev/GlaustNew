{{-- Modal: create a counterparty without leaving the current form; the new record is selected in the counterparty_id combobox. --}}
<div x-data="{
        open: false, busy: false, errors: {},
        form: { type: 'customer', entity_type: 'legal', name: '', voen: '', phone: '', email: '' },
        async submit() {
            this.busy = true; this.errors = {};
            try {
                const d = await glaustApi(@js(route('ajax.counterparties.store')), { method: 'POST', body: this.form });
                if (!d.ok) { this.errors = d.errors || {}; if (d.message) toast('error', d.message); return; }
                window.dispatchEvent(new CustomEvent('combobox:set:counterparty_id', { detail: d.item }));
                window.dispatchEvent(new CustomEvent('combobox-change', { detail: { name: 'counterparty_id', item: d.item } }));
                toast('success', d.item.label + ' əlavə edildi');
                this.open = false;
                this.form = { type: 'customer', entity_type: 'legal', name: '', voen: '', phone: '', email: '' };
            } catch (e) { toast('error', e.message); } finally { this.busy = false; }
        }
     }"
     @quick-counterparty.window="open = true; $nextTick(() => $refs.name.focus())"
     x-cloak x-show="open" class="fixed inset-0 z-[75] grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="qc-title" @keydown.escape.window="open = false">
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="open = false"></div>
    <form x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
          @submit.prevent="submit()" class="relative w-full max-w-lg card !shadow-[var(--shadow-pop)] p-6" x-trap.noscroll="open">
        <div class="flex items-center justify-between mb-5">
            <h2 id="qc-title" class="text-lg font-semibold">Yeni kontragent</h2>
            <button type="button" class="btn btn-ghost btn-icon" @click="open = false" aria-label="Bağla"><x-icon name="x" class="size-5"/></button>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2 grid grid-cols-3 gap-2">
                <template x-for="t in [['customer', 'Müştəri'], ['supplier', 'Təchizatçı'], ['both', 'Hər ikisi']]" :key="t[0]">
                    <label class="flex items-center justify-center h-10 rounded-lg border text-sm font-medium cursor-pointer transition-colors"
                           :class="form.type === t[0] ? 'border-brand bg-brand-soft text-brand-ink' : 'border-line text-ink-2 hover:border-line-strong'">
                        <input type="radio" class="sr-only" x-model="form.type" :value="t[0]"><span x-text="t[1]"></span>
                    </label>
                </template>
            </div>
            <div class="sm:col-span-2">
                <label class="field-label" for="qc-name">Ad <span class="text-danger">*</span></label>
                <input id="qc-name" x-ref="name" class="input" x-model="form.name" required :class="errors.name && 'is-invalid'">
                <p class="field-error" x-show="errors.name" x-text="errors.name?.[0]"></p>
            </div>
            <div>
                <label class="field-label" for="qc-entity">Şəxs</label>
                <select id="qc-entity" class="input" x-model="form.entity_type"><option value="legal">Hüquqi şəxs</option><option value="individual">Fiziki şəxs</option></select>
            </div>
            <div>
                <label class="field-label" for="qc-voen">VÖEN</label>
                <input id="qc-voen" class="input font-mono" x-model="form.voen" maxlength="10" inputmode="numeric" :class="errors.voen && 'is-invalid'">
                <p class="field-error" x-show="errors.voen" x-text="errors.voen?.[0]"></p>
            </div>
            <div>
                <label class="field-label" for="qc-phone">Telefon</label>
                <input id="qc-phone" class="input" x-model="form.phone">
            </div>
            <div>
                <label class="field-label" for="qc-email">Email</label>
                <input id="qc-email" type="email" class="input" x-model="form.email" :class="errors.email && 'is-invalid'">
                <p class="field-error" x-show="errors.email" x-text="errors.email?.[0]"></p>
            </div>
        </div>
        <p class="mt-4 text-xs text-muted">Digər rekvizitləri (IBAN, ünvan, əlaqə şəxsləri) sonra CRM-də əlavə edə bilərsiniz.</p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn btn-secondary" @click="open = false">Ləğv et</button>
            <button class="btn btn-primary" :disabled="busy"><x-icon name="check" class="size-4"/> Əlavə et və seç</button>
        </div>
    </form>
</div>
