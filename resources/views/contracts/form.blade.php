@php
    $editing = $contract->exists;
    $title = $editing ? 'Müqavilə '.$contract->number : ($contract->parent_id ? 'Əlavə razılaşma' : 'Yeni müqavilə');
    $users = \App\Models\User::forTenant()->where('is_active', true)->orderBy('name')->pluck('name', 'id');
    $paymentRows = old('payments', $payments);
@endphp
<x-layouts.app :title="$title">
    <x-page-header :title="$title" :back="$editing ? route('contracts.show', $contract) : route('contracts.index')"
                   :subtitle="__('Müqavilə yalnız CRM-də qeydiyyatda olan müştəri və ya təchizatçı ilə bağlanır')"/>

    @if($errors->any())
        <div class="card border-danger/30 bg-danger-soft/50 p-4 mb-6 text-sm text-danger flex gap-2" role="alert">
            <x-icon name="alert" class="size-5 shrink-0"/> Formda {{ $errors->count() }} xəta var — aşağıdakı sahələri yoxlayın.
        </div>
    @endif

    <form method="POST" action="{{ $editing ? route('contracts.update', $contract) : route('contracts.store') }}"
          x-data="{ kind: @js(old('kind', $contract->kind)), cpType: @js($contract->counterparty?->type), busy: false }"
          @combobox-change.window="if ($event.detail.name === 'counterparty_id') { cpType = $event.detail.item?.type; if (cpType === 'customer') kind = 'sale'; if (cpType === 'supplier') kind = 'purchase'; }"
          @submit="busy = true" class="grid xl:grid-cols-[minmax(0,1fr)_360px] gap-6 items-start">
        @csrf
        @if($editing) @method('PUT') @endif
        @if($contract->parent_id)<input type="hidden" name="parent_id" value="{{ $contract->parent_id }}">@endif

        <div class="space-y-6 min-w-0">
            <section class="card p-6">
                <h2 class="text-base font-semibold mb-5">{{ __('Tərəflər') }}</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <x-combobox name="counterparty_id" :label="__('Kontragent')" :url="route('ajax.lookup', 'counterparties')" required
                                :value="$contract->counterparty_id" :display="$contract->counterparty?->name" :placeholder="__('Müştəri və ya təchizatçı seçin')"
                                wrapper="sm:col-span-2" :hint="__('Siyahıda yoxdursa, «Yeni kontragent» ilə formdan çıxmadan əlavə edin.')">
                        @can('crm.create')
                            <x-slot:footer>
                                <button type="button" class="w-full flex items-center gap-2 px-3 h-9 rounded-lg text-sm font-medium text-brand-ink hover:bg-brand-soft" @click="open = false; $dispatch('quick-counterparty')">
                                    <x-icon name="plus" class="size-4"/> {{ __('Yeni kontragent') }}
                                </button>
                            </x-slot:footer>
                        @endcan
                    </x-combobox>

                    <fieldset class="sm:col-span-2">
                        <legend class="field-label">{{ __('Müqavilənin növü') }} <span class="text-danger">*</span></legend>
                        <div class="grid sm:grid-cols-2 gap-2">
                            @foreach(['sale' => ['Satış', 'Müştəri ilə — biz satırıq / xidmət göstəririk', 'arrow-up-right'], 'purchase' => ['Alış', 'Təchizatçı ilə — biz alırıq / xidmət alırıq', 'arrow-down-left']] as $val => [$label, $hint, $icon])
                                <label class="flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all"
                                       :class="kind === '{{ $val }}' ? 'border-brand bg-brand-soft/60 ring-1 ring-brand/30' : 'border-line hover:border-line-strong'">
                                    <input type="radio" name="kind" value="{{ $val }}" x-model="kind" class="sr-only">
                                    <x-icon :name="$icon" class="size-5 mt-0.5 shrink-0 text-muted"/>
                                    <span><span class="block text-sm font-medium">{{ $label }}</span><span class="block text-xs text-muted">{{ $hint }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        <p x-show="(kind === 'sale' && cpType === 'supplier') || (kind === 'purchase' && cpType === 'customer')" x-cloak class="field-error">
                            <x-icon name="alert" class="size-3.5"/> {{ __('Seçilmiş kontragentin növü bu müqavilə növünə uyğun deyil.') }}
                        </p>
                        @error('kind')<p class="field-error"><x-icon name="alert" class="size-3.5"/> {{ $message }}</p>@enderror
                    </fieldset>
                </div>
            </section>

            <section class="card p-6" x-data="rateLookup({ currency: @js(old('currency', $contract->currency)), date: @js(old('contract_date', $contract->contract_date?->format('Y-m-d'))), amount: @js((string) old('amount', $contract->amount)) })">
                <h2 class="text-base font-semibold mb-5">{{ __('Müqavilənin şərtləri') }}</h2>
                <div class="grid sm:grid-cols-6 gap-4">
                    <x-input name="number" :label="__('Nömrə')" :value="$contract->number" required wrapper="sm:col-span-2" class="font-mono" :hint="__('Avtomatik verilir, dəyişə bilərsiniz.')"/>
                    <x-input name="contract_date" type="date" :label="__('Müqavilə tarixi')" :value="$contract->contract_date" required wrapper="sm:col-span-2" x-model="date"/>
                    <x-select name="status" :label="__('Status')" :options="status_options('contract')" :value="$contract->status" required wrapper="sm:col-span-2"/>
                    <x-input name="subject" :label="__('Mövzu')" :value="$contract->subject" required wrapper="sm:col-span-6" :placeholder="__('Məs: Avadanlığın Trade-i və quraşdırılması')"/>
                    <x-input name="amount" :label="__('Məbləğ')" :value="$contract->amount" required wrapper="sm:col-span-3" inputmode="decimal" class="font-mono text-right" x-model="amount"/>
                    <x-select name="currency" :label="__('Valyuta')" :options="array_combine(config('glaust.currencies'), config('glaust.currencies'))" :value="$contract->currency" required wrapper="sm:col-span-3" x-model="currency"/>
                </div>
                <div class="mt-4 rounded-xl border border-line bg-surface-2 px-4 py-3 text-sm flex flex-wrap items-center gap-x-6 gap-y-1">
                    <span class="text-muted">{{ __('CBAR məzənnəsi (') }}<span x-text="date ? date.split('-').reverse().join('.') : '—'"></span>):</span>
                    <span class="font-mono" x-show="!loading && !error" x-text="rate(cbar)"></span>
                    <span x-show="loading" class="text-muted">{{ __('yüklənir…') }}</span>
                    <span class="text-muted ml-auto">{{ __('AZN ekvivalenti:') }}</span>
                    <span class="font-mono font-semibold text-ink" x-text="cbar ? money(cbarAzn) + ' ₼' : '—'"></span>
                    <p x-show="error" x-cloak class="w-full text-saffron text-xs flex items-center gap-1"><x-icon name="alert" class="size-3.5"/> <span x-text="error"></span></p>
                </div>
            </section>

            <section class="card p-6">
                <h2 class="text-base font-semibold mb-5">{{ __('Müddət') }}</h2>
                <div class="grid sm:grid-cols-3 gap-4 items-end">
                    <x-input name="start_date" type="date" :label="__('Başlama tarixi')" :value="$contract->start_date"/>
                    <x-input name="end_date" type="date" :label="__('Bitmə tarixi')" :value="$contract->end_date" :hint="__('Bitmədən 30, 7 və 1 gün əvvəl xatırlatma gələcək.')"/>
                    <label class="flex items-center gap-2.5 h-10 text-sm cursor-pointer">
                        <input type="hidden" name="auto_renew" value="0">
                        <input type="checkbox" name="auto_renew" value="1" class="checkbox" @checked(old('auto_renew', $contract->auto_renew))> {{ __('Avtomatik uzadılır') }}
                    </label>
                </div>
                <x-input name="payment_terms" :label="__('Ödəniş şərtləri')" :value="$contract->payment_terms" wrapper="mt-4" :placeholder="__('Məs: 30% avans, qalan təhvildən sonra 15 gün ərzində')"/>
            </section>

            <section class="card p-6" x-data="repeater(@js(array_values($paymentRows)), { due_date: '', amount: '', note: '', paid: false })">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-base font-semibold">{{ __('Ödəniş qrafiki') }}</h2>
                        <p class="text-xs text-muted">{{ __('Hər mərhələdən əvvəl məsul şəxsə xatırlatma göndərilir.') }}</p>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" @click="add()"><x-icon name="plus" class="size-4"/> {{ __('Mərhələ') }}</button>
                </div>
                <template x-if="!rows.length"><p class="text-sm text-muted">{{ __('Mərhələli ödəniş yoxdur.') }}</p></template>
                <div class="space-y-2">
                    <template x-for="(row, i) in rows" :key="row._k ?? i">
                        <div class="grid grid-cols-[1fr_1fr] sm:grid-cols-[150px_150px_1fr_auto_auto] gap-2 items-center p-2.5 rounded-xl bg-surface-2 border border-line rise">
                            <input type="date" class="input font-mono" :name="`payments[${i}][due_date]`" x-model="row.due_date" aria-label="{{ __('Tarix') }}" required>
                            <input class="input font-mono text-right" :name="`payments[${i}][amount]`" x-model="row.amount" placeholder="{{ __('Məbləğ') }}" inputmode="decimal" aria-label="{{ __('Məbləğ') }}" required>
                            <input class="input col-span-2 sm:col-span-1" :name="`payments[${i}][note]`" x-model="row.note" placeholder="{{ __('Qeyd (məs: avans)') }}" aria-label="{{ __('Qeyd') }}">
                            <label class="flex items-center gap-1.5 text-xs text-muted px-1"><input type="checkbox" class="checkbox" :name="`payments[${i}][paid]`" value="1" x-model="row.paid"> {{ __('Ödənilib') }}</label>
                            <button type="button" class="btn btn-ghost btn-icon text-danger justify-self-end" @click="remove(i)" aria-label="{{ __('Sil') }}"><x-icon name="trash" class="size-4"/></button>
                        </div>
                    </template>
                </div>
                @error('payments')<p class="field-error">{{ $message }}</p>@enderror
                @error('payments.*')<p class="field-error">{{ $message }}</p>@enderror
            </section>
        </div>

        <aside class="space-y-6 xl:sticky xl:top-24">
            <section class="card p-6 space-y-4">
                <x-select name="responsible_id" :label="__('Məsul şəxs')" :options="$users" :value="$contract->responsible_id" :placeholder="__('— seçilməyib —')"/>
                <x-combobox name="project_id" :label="__('Layihə')" :url="route('ajax.lookup', 'projects')" :value="$contract->project_id" :display="$contract->project?->name" :placeholder="__('Layihəyə bağla (istəyə bağlı)')"/>
                <x-input name="notes" type="textarea" :label="__('Qeydlər')" :value="$contract->notes" rows="4"/>
            </section>
            <div class="flex gap-2">
                <a href="{{ $editing ? route('contracts.show', $contract) : route('contracts.index') }}" class="btn btn-secondary flex-1">{{ __('Ləğv et') }}</a>
                <button class="btn btn-primary flex-1" :disabled="busy"><x-icon name="check" class="size-4"/> {{ $editing ? 'Yadda saxla' : 'Yarat' }}</button>
            </div>
        </aside>
    </form>

    @can('crm.create')
        @include('counterparties._quick-create')
    @endcan
</x-layouts.app>
