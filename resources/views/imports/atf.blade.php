<x-layouts.app :title="__('Toplu Trade importu')">
    <x-page-header :title="__('Toplu Trade importu — ATF cədvəli')" icon="layers" :back="route('imports.index')"
                   :subtitle="__('Hər sətir bir Trade-dir: faktura, mədaxil, rubl satışı, avro alışı, satıcıya ödəniş və logistika — sanki əl ilə başdan sona işlənib')"/>

    @php
        $partyOpts = fn ($types) => $parties->filter(fn ($c) => in_array($c->type, $types, true))->mapWithKeys(fn ($c) => [$c->id => $c->name])->all();
        $acc = fn ($cur) => $accounts->where('currency', $cur)->mapWithKeys(fn ($a) => [$a->id => $a->name.' · '.$a->bank_name])->all();
        $contractOpts = fn ($kind) => $contracts->where('kind', $kind)->mapWithKeys(fn ($c) => [$c->id => $c->number.' · '.($c->counterparty?->name ?? '')])->all();
    @endphp

    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
        <form method="POST" action="{{ route('imports.atf.store') }}" enctype="multipart/form-data" class="card p-6 space-y-6" x-data="{ file: '', newProject: {{ old('project_name') ? 'true' : 'false' }}, busy: false }" @submit="busy = true">
            @csrf
            <section>
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <h2 class="text-sm font-semibold">1. {{ __('ATF faylı') }}</h2>
                    <a href="{{ route('imports.atf.template') }}" class="btn btn-secondary btn-sm"><x-icon name="download" class="size-4"/> {{ __('ATF şablonunu yüklə') }}</a>
                </div>
                <label class="flex flex-col items-center justify-center gap-2 h-36 rounded-2xl border-2 border-dashed cursor-pointer transition-colors text-center px-4"
                       :class="file ? 'border-brand bg-brand-soft/40' : 'border-line hover:border-brand hover:bg-brand-soft/30'">
                    <x-icon name="sheet" class="size-8 text-brand"/>
                    <span class="text-sm font-medium" x-text="file || {{ \Illuminate\Support\Js::from(__('ATF (kurslarla) .xlsx faylını seçin')) }}"></span>
                    <span class="text-xs text-muted">{{ __('«ATF» vərəqi oxunur; düsturlar sistemdə yenidən hesablanır') }}</span>
                    <input type="file" name="file" accept=".xlsx,.xls" class="sr-only" required @change="file = $event.target.files[0]?.name">
                </label>
                @error('file')<p class="field-error">{{ $message }}</p>@enderror
            </section>

            <section>
                <h2 class="text-sm font-semibold mb-3">2. {{ __('Layihə') }}</h2>
                <div class="flex gap-1 p-1 rounded-lg bg-surface-2 border border-line w-fit mb-3">
                    <button type="button" class="px-3 h-8 rounded-md text-xs font-medium" :class="!newProject ? 'bg-surface shadow-sm text-ink' : 'text-muted'" @click="newProject = false; $root.querySelector('[name=project_name]').value = ''">{{ __('Mövcud layihə') }}</button>
                    <button type="button" class="px-3 h-8 rounded-md text-xs font-medium" :class="newProject ? 'bg-surface shadow-sm text-ink' : 'text-muted'" @click="newProject = true">{{ __('Yeni layihə') }}</button>
                </div>
                <div x-show="!newProject"><x-select name="project_id" :label="__('Layihə')" :options="$projects->mapWithKeys(fn ($p) => [$p->id => $p->code.' · '.$p->name])->all()" :placeholder="__('— seçin —')"/></div>
                <div x-show="newProject" x-cloak><x-input name="project_name" :label="__('Yeni layihənin adı')" placeholder="ATF 2025–2026"/></div>
            </section>

            <section>
                <h2 class="text-sm font-semibold mb-3">3. {{ __('Tərəflər') }}</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <x-select name="supplier_id" :label="__('Satıcı (məs. Ellis)')" :options="$partyOpts(['supplier', 'both'])" :value="old('supplier_id', $guess['supplier'])" :placeholder="__('— seçin —')" required/>
                    <x-select name="purchase_contract_id" :label="__('Alış müqaviləsi')" :options="$contractOpts('purchase')" :placeholder="__('— istəyə bağlı —')"/>
                    <x-select name="buyer_id" :label="__('Alıcı (məs. Axios)')" :options="$partyOpts(['customer', 'both'])" :value="old('buyer_id', $guess['buyer'])" :placeholder="__('— seçin —')" required/>
                    <x-select name="sale_contract_id" :label="__('Satış müqaviləsi')" :options="$contractOpts('sale')" :placeholder="__('— istəyə bağlı —')"/>
                    <x-select name="logistics_id" :label="__('Logistika şirkəti')" :options="$partyOpts(['logistics', 'supplier', 'both'])" :value="old('logistics_id', $guess['logistics'])" :placeholder="__('— istəyə bağlı —')"/>
                </div>
            </section>

            <section>
                <h2 class="text-sm font-semibold mb-3">4. {{ __('Bank hesabları') }}</h2>
                <div class="grid sm:grid-cols-3 gap-4">
                    <x-select name="rub_account" :label="__('RUB hesabı')" :options="$acc('RUB')" :hint="__('mədaxil, rubl satışı, logistika')" required/>
                    <x-select name="azn_account" :label="__('AZN hesabı')" :options="$acc('AZN')" :hint="__('valyuta alış-satışı, logistika komissiyası')" required/>
                    <x-select name="eur_account" :label="__('EUR hesabı')" :options="$acc('EUR')" :hint="__('avro alışı, satıcıya ödəniş')" required/>
                </div>
            </section>

            <div class="flex justify-end">
                <button class="btn btn-primary" :disabled="busy"><x-icon name="arrow-right" class="size-4"/> <span x-text="busy ? {{ \Illuminate\Support\Js::from(__('Oxunur…')) }} : {{ \Illuminate\Support\Js::from(__('Yoxla və davam et')) }}"></span></button>
            </div>
        </form>

        <aside class="card p-5 space-y-3 text-sm">
            <h3 class="font-semibold">{{ __('Hər sətir üçün nə edilir') }}</h3>
            <ol class="space-y-2 list-decimal pl-4 text-ink-2">
                <li>{{ __('Trade yaradılır (satıcı, alıcı, müqavilələr).') }}</li>
                <li>{{ __('Satıcı fakturası (D) fakturasız məbləğ kimi; alıcıya yekun məbləğ (H), logistika proqnozu (I), proqnoz kursları (N, O).') }}</li>
                <li>{{ __('Alıcıdan mədaxil (H) — daxilolma tarixinə (T), CBAR ilə.') }}</li>
                <li>{{ __('Rubl satışı (AO, AG) və avro alışı (AP, AF) — əməliyyat tarixinə (X).') }}</li>
                <li>{{ __('Satıcıya ödəniş (D) və köçürmə komissiyası (J).') }}</li>
                <li>{{ __('Logistika invoysu / aktı (BF–BI) və ödənişi (AX, AY); komissiya (BB) AZN hesabından bank kursu (BA) ilə.') }}</li>
            </ol>
            <p class="text-xs text-muted pt-2 border-t border-line">{{ __('Şablonda sütunlar ATF cədvəlinizlə eyni ardıcıllıqdadır (A…BT): sətirləri 3-cü sətirdən olduğu kimi kopyalayıb yapışdıra bilərsiniz. Yaşıl sütunlar oxunur, qalanları sistem özü hesablayır.') }}</p>
            <p class="text-xs text-muted">{{ __('Növbəti addımda hər sətri görüb yoxlayacaqsınız. Artıq import edilmiş satıcı fakturası (eyni layihədə eyni nömrə) təkrar yaradılmır.') }}</p>
        </aside>
    </div>
</x-layouts.app>
