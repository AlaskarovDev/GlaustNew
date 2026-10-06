{{-- The order of the calculation, as in the company's ATF workbook (column letters as in the workbook). --}}
<details class="card mt-6 group">
    <summary class="flex items-center gap-2 px-5 py-4 cursor-pointer select-none list-none">
        <x-icon name="list-checks" class="size-5 text-brand"/>
        <span class="font-semibold">{{ __('Hesablama ardıcıllığı') }}</span>
        <span class="text-xs text-muted hidden sm:inline">· {{ __('Hər satıcı fakturası (maşın) ayrıca hesablanır; Trade və layihə nəticəsi onların cəmidir. Bütün məbləğlər AZN-dədir.') }}</span>
        <x-icon name="chevron-down" class="size-4 text-muted ml-auto transition-transform group-open:rotate-180"/>
    </summary>
    <div class="px-5 pb-5 grid sm:grid-cols-2 xl:grid-cols-4 gap-4 text-sm">
        @foreach([
            [1, __('Proqnoz'), __('Faktura tarixinə proqnoz kursları (N, O) ilə'), ['P = D × N', 'Q = H × O', 'Q − P − R − S']],
            [2, __('Akt tarixinə mənfəət'), __('Son logistika aktının tarixinə CBAR kursları ilə — əməliyyat akt tarixinə bitir'), ['BL = D × BJ', 'BM = H × BK', 'BP = BM − BL − BO']],
            [3, __('Kurs fərqləri və xalis mənfəət'), __('Satıcıya ödəniş günü ilə akt günü arasındakı CBAR fərqləri, köçürmə komissiyaları'), ['BQ = AC − BM', 'BR = BL − AB', 'BS = BC − BO', 'BT = BP + BQ + BR − BS − AJ − BB']],
            [4, __('Bank kursları və yekun'), __('Bankın real kursu ilə CBAR arasındakı fərq (rubl satışı, avro alışı, logistika) və Trade-ə bağlı digər xərclər'), ['BE = BT − AL − AM − '.__('xərclər')]],
        ] as [$n, $title, $text, $formulas])
            <div class="rounded-xl bg-surface-2/60 p-4">
                <div class="flex items-center gap-2 font-medium"><span class="pf-stage-no !bg-brand !text-white">{{ $n }}</span> {{ $title }}</div>
                <p class="mt-1.5 text-xs text-muted">{{ $text }}</p>
                <div class="mt-2 space-y-0.5 font-mono text-[11px] text-ink-2">@foreach($formulas as $f)<div>{{ $f }}</div>@endforeach</div>
            </div>
        @endforeach
        <p class="sm:col-span-2 xl:col-span-4 text-[11px] text-faint">{{ __('D — satıcının fakturası, H — alıcının proforması, öz valyutalarında. Ödənilməmiş hissələr bugünkü CBAR kursu ilə təxmini götürülür.') }}</p>
    </div>
</details>
