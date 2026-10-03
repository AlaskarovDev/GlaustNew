<x-layouts.app title="Təsdiq axını">
    <x-page-header title="Tənzimləmələr" icon="settings"/>
    @include('settings._nav')
    @php
        $rows = collect(old('steps', $steps))->map(fn ($s) => ['user_id' => (string) ($s['user_id'] ?? ''), 'title' => (string) ($s['title'] ?? '')])->values();
        $canEdit = auth()->user()->can('settings.update');
    @endphp
    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start max-w-6xl">
        <form method="POST" action="{{ route('settings.approvals.update') }}" class="card" x-data="{
                steps: @js($rows),
                add() { this.steps.push({ user_id: '', title: '' }); },
                remove(i) { this.steps.splice(i, 1); },
                move(i, d) { const j = i + d; if (j < 0 || j >= this.steps.length) return; [this.steps[i], this.steps[j]] = [this.steps[j], this.steps[i]]; },
             }">
            @csrf @method('PUT')
            <header class="px-6 py-5 border-b border-line">
                <h2 class="text-base font-semibold">Fakturanın təsdiq axını</h2>
                <p class="text-sm text-muted">Hesablaması bitmiş satıcı fakturası «Təsdiqə göndər» ilə bu şəxslərə <b>ardıcıl</b> göndərilir. Hər addımdakı şəxs bildiriş (zəng ikonu + e-poçt) alır; hamısı təsdiqləyəndə kommersiya fakturası hazırlanır və faktura kilidlənir.</p>
            </header>
            <fieldset @disabled(! $canEdit) class="p-6 space-y-3">
                <template x-if="!steps.length">
                    <p class="rounded-xl border border-dashed border-line-strong px-4 py-6 text-center text-sm text-muted">Axın boşdur — fakturalar təsdiqə göndərilə bilməz. Ən azı bir təsdiqləyən əlavə edin.</p>
                </template>
                <ol class="space-y-3">
                    <template x-for="(s, i) in steps" :key="i">
                        <li class="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-surface p-3">
                            <span class="grid place-items-center size-8 rounded-full bg-brand text-white font-mono text-sm font-semibold shrink-0" x-text="i + 1"></span>
                            <select :name="`steps[${i}][user_id]`" x-model="s.user_id" class="input flex-1 min-w-[200px]" :aria-label="'Təsdiqləyən, addım ' + (i + 1)" required>
                                <option value="">— Şəxs seçin —</option>
                                @foreach($users as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }}{{ $u->position ? ' · '.$u->position : '' }}</option>
                                @endforeach
                            </select>
                            <input :name="`steps[${i}][title]`" x-model="s.title" placeholder="Vəzifə / addım adı (məs: Maliyyə direktoru)" class="input flex-1 min-w-[200px]" :aria-label="'Addım adı ' + (i + 1)">
                            <div class="flex items-center">
                                <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="move(i, -1)" :disabled="i === 0" aria-label="Yuxarı"><x-icon name="chevron-up" class="size-4"/></button>
                                <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="move(i, 1)" :disabled="i === steps.length - 1" aria-label="Aşağı"><x-icon name="chevron-down" class="size-4"/></button>
                                <button type="button" class="btn btn-ghost btn-icon btn-sm text-danger" @click="remove(i)" aria-label="Addımı sil"><x-icon name="trash" class="size-4"/></button>
                            </div>
                        </li>
                    </template>
                </ol>
                @error('steps')<p class="field-error">{{ $message }}</p>@enderror
                @foreach($errors->get('steps.*') as $msgs)<p class="field-error">{{ $msgs[0] }}</p>@endforeach
                @if($canEdit)
                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <button type="button" class="btn btn-secondary" @click="add()"><x-icon name="plus" class="size-4"/> Təsdiqləyən əlavə et</button>
                        <button class="btn btn-primary ml-auto"><x-icon name="check" class="size-4"/> Yadda saxla</button>
                    </div>
                @endif
            </fieldset>
        </form>

        <aside class="card p-5 space-y-3 text-sm">
            <h3 class="font-semibold">Necə işləyir</h3>
            <ol class="space-y-2 text-muted list-decimal pl-4">
                <li>Fakturada logistika, komissiya və RUB konvertasiyası tətbiq olunur — proforma və spesifikasiya yaranır.</li>
                <li>«Fakturanı təsdiqə göndər» — faktura və sənədləri kilidlənir.</li>
                <li>1-ci şəxs təsdiqləyir → 2-ci şəxsə keçir … Geri qaytarılarsa səbəb göndərənə bildirilir və kilid açılır.</li>
                <li>Son təsdiqdən sonra <b class="text-ink">Commercial Invoice</b> avtomatik hazırlanır və yüklənə bilər.</li>
            </ol>
            <p class="text-xs text-faint">Axındakı dəyişiklik artıq təsdiqdə olan fakturalara təsir etmir — onlar göndərildiyi andakı axınla davam edir.</p>
        </aside>
    </div>
</x-layouts.app>
