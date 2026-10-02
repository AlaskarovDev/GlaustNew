<x-layouts.app title="Ümumi ayarlar">
    <x-page-header title="Tənzimləmələr" icon="settings"/>
    @include('settings._nav')
    @php $ticker = old('ticker_currencies', $company->setting('ticker_currencies')); @endphp
    <form method="POST" action="{{ route('settings.general.update') }}" class="space-y-6 max-w-4xl">
        @csrf @method('PUT')
        <section class="card p-6">
            <h2 class="text-base font-semibold">Header məzənnə lenti</h2>
            <p class="text-sm text-muted mb-4">Yuxarı paneldə animasiyalı görünəcək valyutalar (CBAR).</p>
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
            <h2 class="text-base font-semibold">Xatırlatma qaydaları</h2>
            <p class="text-sm text-muted mb-4">Neçə gün əvvəl xatırladılsın — günləri vergüllə yazın. Boş qoysanız, həmin xatırlatma söndürülür.</p>
            <div class="grid sm:grid-cols-2 gap-4">
                <x-input name="contract_reminder_days" label="Müqavilənin bitməsi" :value="implode(', ', (array) $company->setting('contract_reminder_days'))" hint="Məs: 30, 7, 1" class="font-mono"/>
                <x-input name="payment_reminder_days" label="Müqavilə ödənişləri" :value="implode(', ', (array) $company->setting('payment_reminder_days'))" hint="0 — ödəniş günü" class="font-mono"/>
                <x-input name="task_reminder_days" label="Tapşırığın son tarixi" :value="implode(', ', (array) $company->setting('task_reminder_days'))" hint="1 — bir gün əvvəl, 0 — həmin gün" class="font-mono"/>
                <x-input name="digest_time" type="time" label="Gündəlik xülasənin vaxtı" :value="$company->setting('digest_time')" required class="font-mono" hint="Hər səhər bu vaxtdan sonra göndərilir (Bakı vaxtı)"/>
            </div>
        </section>

        <section class="card p-6">
            <h2 class="text-base font-semibold">Nömrələmə şablonları</h2>
            <p class="text-sm text-muted mb-4"><span class="font-mono">{Y}</span> il, <span class="font-mono">{y}</span> ilin son 2 rəqəmi, <span class="font-mono">{M}</span> ay, <span class="font-mono">{SEQ:4}</span> sıra nömrəsi (4 rəqəm).</p>
            <div class="grid sm:grid-cols-3 gap-4">
                <x-input name="numbering[project]" label="Layihə kodu" :value="$company->setting('numbering.project')" required class="font-mono"/>
                <x-input name="numbering[contract]" label="Müqavilə nömrəsi" :value="$company->setting('numbering.contract')" required class="font-mono"/>
                <x-input name="numbering[shipment]" label="Yük nömrəsi" :value="$company->setting('numbering.shipment')" required class="font-mono"/>
            </div>
        </section>
        <div class="flex justify-end"><button class="btn btn-primary" @cannot('settings.update') disabled @endcannot>Yadda saxla</button></div>
    </form>
</x-layouts.app>
