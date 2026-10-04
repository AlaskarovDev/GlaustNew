<x-layouts.app :title="__('Ümumi ayarlar')">
    <x-page-header :title="__('Tənzimləmələr')" icon="settings"/>
    @include('settings._nav')
    @php $ticker = old('ticker_currencies', $company->setting('ticker_currencies')); @endphp
    <form method="POST" action="{{ route('settings.general.update') }}" class="space-y-6 max-w-4xl">
        @csrf @method('PUT')
        <section class="card p-6">
            <h2 class="text-base font-semibold">{{ __('Header məzənnə lenti') }}</h2>
            <p class="text-sm text-muted mb-4">{{ __('Yuxarı paneldə animasiyalı görünəcək valyutalar (CBAR).') }}</p>
            <div class="flex flex-wrap gap-2">
                @foreach(array_diff(config('glaust.currencies'), ['AZN']) as $cur)
                    <label class="inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-line cursor-pointer has-[:checked]:border-brand has-[:checked]:bg-brand-soft transition-colors">
                        <input type="checkbox" name="ticker_currencies[]" value="{{ $cur }}" class="checkbox" @checked(in_array($cur, $ticker, true))>
                        <span class="text-sm font-mono">{{ $cur }}</span>
                    </label>
                @endforeach
            </div>
            @error('ticker_currencies')<p class="field-error">{{ $message }}</p>@enderror
        </section>

        <section class="card p-6">
            <h2 class="text-base font-semibold">{{ __('Xatırlatma qaydaları') }}</h2>
            <p class="text-sm text-muted mb-4">{{ __('Neçə gün əvvəl xatırladılsın — günləri vergüllə yazın. Boş qoysanız, həmin xatırlatma söndürülür.') }}</p>
            <div class="grid sm:grid-cols-2 gap-4">
                <x-input name="contract_reminder_days" :label="__('Müqavilənin bitməsi')" :value="implode(', ', (array) $company->setting('contract_reminder_days'))" :hint="__('Məs: 30, 7, 1')" class="font-mono"/>
                <x-input name="payment_reminder_days" :label="__('Müqavilə ödənişləri')" :value="implode(', ', (array) $company->setting('payment_reminder_days'))" :hint="__('0 — ödəniş günü')" class="font-mono"/>
                <x-input name="task_reminder_days" :label="__('Tapşırığın son tarixi')" :value="implode(', ', (array) $company->setting('task_reminder_days'))" :hint="__('1 — bir gün əvvəl, 0 — həmin gün')" class="font-mono"/>
                <x-input name="digest_time" type="time" :label="__('Gündəlik xülasənin vaxtı')" :value="$company->setting('digest_time')" required class="font-mono" :hint="__('Hər səhər bu vaxtdan sonra göndərilir (Bakı vaxtı)')"/>
            </div>
        </section>

        <section class="card p-6">
            <h2 class="text-base font-semibold">{{ __('Nömrələmə şablonları') }}</h2>
            <p class="text-sm text-muted mb-4"><span class="font-mono">{Y}</span> {{ __('il,') }} <span class="font-mono">{y}</span> {{ __('ilin son 2 rəqəmi,') }} <span class="font-mono">{M}</span> {{ __('ay,') }} <span class="font-mono">{SEQ:4}</span> {{ __('sıra nömrəsi (4 rəqəm).') }}</p>
            <div class="grid sm:grid-cols-3 gap-4">
                <x-input name="numbering[project]" :label="__('Layihə kodu')" :value="$company->setting('numbering.project')" required class="font-mono"/>
                <x-input name="numbering[contract]" :label="__('Müqavilə nömrəsi')" :value="$company->setting('numbering.contract')" required class="font-mono"/>
                <x-input name="numbering[shipment]" :label="__('Yük nömrəsi')" :value="$company->setting('numbering.shipment')" required class="font-mono"/>
            </div>
        </section>
        <div class="flex justify-end"><button class="btn btn-primary" @cannot('settings.update') disabled @endcannot>{{ __('Yadda saxla') }}</button></div>
    </form>
</x-layouts.app>
