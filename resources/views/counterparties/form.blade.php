@php
    $editing = $item->exists;
    $contacts = old('contacts', $item->relationLoaded('contacts') ? $item->contacts->map->only(['name', 'position', 'phone', 'email'])->all() : []);
    $title = $editing ? $item->name : ($item->type === 'supplier' ? 'Yeni təchizatçı' : 'Yeni müştəri');
@endphp
<x-layouts.app :title="$title">
    <x-page-header :title="$title" :back="$editing ? route('counterparties.show', $item) : route('counterparties.index')"
                   :subtitle="__('Məlumatlar müqavilələrdə, bank əməliyyatlarında və hesabatlarda istifadə olunur')"/>

    <form method="POST" action="{{ $editing ? route('counterparties.update', $item) : route('counterparties.store') }}" class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start"
          x-data="{ type: @js(old('type', $item->type)), entity: @js(old('entity_type', $item->entity_type)), busy: false }" @submit="busy = true">
        @csrf
        @if($editing) @method('PUT') @endif
        @if(request('return_to'))<input type="hidden" name="return_to" value="{{ request('return_to') }}">@endif

        <div class="space-y-6">
            <section class="card p-6">
                <h2 class="text-base font-semibold mb-5">{{ __('Əsas məlumatlar') }}</h2>
                <fieldset class="mb-5">
                    <legend class="field-label">{{ __('Növ') }} <span class="text-danger">*</span></legend>
                    <div class="grid sm:grid-cols-3 gap-2">
                        @foreach(['customer' => ['Müştəri', 'user', 'Ona satırıq / xidmət göstəririk'], 'supplier' => ['Təchizatçı', 'building', 'Ondan alırıq / daşıyıcı'], 'both' => ['Hər ikisi', 'transfer', 'Həm alır, həm satır']] as $val => [$label, $icon, $hint])
                            <label class="relative flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all"
                                   :class="type === '{{ $val }}' ? 'border-brand bg-brand-soft/60 ring-1 ring-brand/30' : 'border-line hover:border-line-strong'">
                                <input type="radio" name="type" value="{{ $val }}" x-model="type" class="sr-only">
                                <x-icon :name="$icon" class="size-5 mt-0.5 shrink-0" ::class="type === '{{ $val }}' ? 'text-brand' : 'text-muted'"/>
                                <span><span class="block text-sm font-medium">{{ $label }}</span><span class="block text-xs text-muted">{{ $hint }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    @error('type')<p class="field-error">{{ $message }}</p>@enderror
                </fieldset>

                <div class="grid sm:grid-cols-2 gap-4">
                    <x-select name="entity_type" :label="__('Şəxs')" :options="config('glaust.entity_types')" :value="$item->entity_type" required x-model="entity"/>
                    <x-input name="voen" :label="__('VÖEN')" :value="$item->voen" inputmode="numeric" maxlength="10" :placeholder="__('10 rəqəm')" class="font-mono"
                             :hint="__('Eyni VÖEN ilə ikinci kontragent yaradıla bilməz.')"/>
                    <x-input name="name" :label="__('Hüquqi ad / Ad, soyad')" :value="$item->name" required wrapper="sm:col-span-2" :placeholder="__('Məs: «Xəzər Logistika» MMC')"/>
                    <x-input name="country" :label="__('Ölkə')" :value="$item->country" required/>
                    <x-input name="city" :label="__('Şəhər')" :value="$item->city"/>
                    <x-input name="address" :label="__('Ünvan')" :value="$item->address" wrapper="sm:col-span-2"/>
                    <x-input name="phone" :label="__('Telefon')" :value="$item->phone" placeholder="+994"/>
                    <x-input name="email" type="email" :label="__('Email')" :value="$item->email"/>
                    <x-input name="website" :label="__('Vebsayt')" :value="$item->website" wrapper="sm:col-span-2"/>
                </div>
            </section>

            <section class="card p-6">
                <h2 class="text-base font-semibold mb-5">{{ __('Bank rekvizitləri') }}</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <x-input name="iban" label="IBAN" :value="$item->iban" wrapper="sm:col-span-2" class="font-mono uppercase" placeholder="AZ00 XXXX 0000 0000 0000 0000 0000" :hint="__('Yoxlama rəqəmləri avtomatik yoxlanılır.')"/>
                    <x-input name="bank_name" :label="__('Bank')" :value="$item->bank_name"/>
                    <x-input name="swift" label="SWIFT / BIC" :value="$item->swift" class="font-mono uppercase" maxlength="11"/>
                </div>
            </section>

            <section class="card p-6" x-data="repeater(@js(array_values($contacts)), { name: '', position: '', phone: '', email: '' })">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-semibold">{{ __('Əlaqə şəxsləri') }}</h2>
                    <button type="button" class="btn btn-secondary btn-sm" @click="add()"><x-icon name="plus" class="size-4"/> {{ __('Əlavə et') }}</button>
                </div>
                <template x-if="!rows.length">
                    <p class="text-sm text-muted py-3">{{ __('Əlaqə şəxsi əlavə edilməyib.') }}</p>
                </template>
                <div class="space-y-3">
                    <template x-for="(row, i) in rows" :key="row._k ?? i">
                        <div class="grid sm:grid-cols-[1fr_1fr_1fr_1fr_auto] gap-2 p-3 rounded-xl bg-surface-2 border border-line rise">
                            <input class="input" :name="`contacts[${i}][name]`" x-model="row.name" placeholder="{{ __('Ad, soyad') }}" aria-label="{{ __('Ad, soyad') }}">
                            <input class="input" :name="`contacts[${i}][position]`" x-model="row.position" placeholder="{{ __('Vəzifə') }}" aria-label="{{ __('Vəzifə') }}">
                            <input class="input" :name="`contacts[${i}][phone]`" x-model="row.phone" placeholder="{{ __('Telefon') }}" aria-label="{{ __('Telefon') }}">
                            <input class="input" type="email" :name="`contacts[${i}][email]`" x-model="row.email" placeholder="{{ __('Email') }}" aria-label="{{ __('Email') }}">
                            <button type="button" class="btn btn-ghost btn-icon text-danger" @click="remove(i)" aria-label="{{ __('Sil') }}"><x-icon name="trash" class="size-4"/></button>
                        </div>
                    </template>
                </div>
                @error('contacts.*')<p class="field-error">{{ $message }}</p>@enderror
            </section>
        </div>

        <aside class="space-y-6 lg:sticky lg:top-24">
            <section class="card p-6 space-y-4">
                <x-input name="tags" :label="__('Etiketlər')" :value="$item->tags" :hint="__('Vergüllə ayırın: VIP, idxal, Bakı')"/>
                <x-input name="notes" type="textarea" :label="__('Qeydlər')" :value="$item->notes" rows="5"/>
            </section>
            <div class="flex gap-2">
                <a href="{{ $editing ? route('counterparties.show', $item) : route('counterparties.index') }}" class="btn btn-secondary flex-1">{{ __('Ləğv et') }}</a>
                <button class="btn btn-primary flex-1" :disabled="busy"><x-icon name="check" class="size-4"/> {{ $editing ? 'Yadda saxla' : 'Əlavə et' }}</button>
            </div>
        </aside>
    </form>
</x-layouts.app>
