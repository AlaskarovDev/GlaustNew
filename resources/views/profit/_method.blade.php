{{-- The order of the calculation, as in the company's ATF workbook (column letters in brackets). --}}
<aside class="card p-5 space-y-4 text-sm 2xl:sticky 2xl:top-24">
    <h3 class="font-semibold flex items-center gap-2"><x-icon name="list-checks" class="size-4 text-brand"/> {{ __('Hesablama ardıcıllığı') }}</h3>
    <p class="text-xs text-muted">{{ __('Hər satıcı fakturası (maşın) ayrıca hesablanır; Trade və layihə nəticəsi onların cəmidir. Bütün məbləğlər AZN-dədir.') }}</p>
    <ol class="space-y-3">
        <li><div class="font-medium">1. {{ __('Proqnoz') }}</div>
            <div class="text-xs text-muted">{{ __('Faktura tarixinə proqnoz kursları (N, O) ilə') }}</div>
            <div class="font-mono text-[11px] mt-1">Q − P − R − S</div></li>
        <li><div class="font-medium">2. {{ __('Akt tarixinə mənfəət') }}</div>
            <div class="text-xs text-muted">{{ __('Son logistika aktının tarixinə CBAR kursları ilə — əməliyyat akt tarixinə bitir') }}</div>
            <div class="font-mono text-[11px] mt-1">BP = (H×BK − D×BJ) − BO</div></li>
        <li><div class="font-medium">3. {{ __('Kurs fərqləri və xalis mənfəət') }}</div>
            <div class="text-xs text-muted">{{ __('Satıcıya ödəniş günü ilə akt günü arasındakı CBAR fərqləri, köçürmə komissiyaları') }}</div>
            <div class="font-mono text-[11px] mt-1">BQ = AC − BM · BR = BL − AB · BS = BC − BO</div>
            <div class="font-mono text-[11px]">BT = BP + BQ + BR − BS − AJ − BB</div></li>
        <li><div class="font-medium">4. {{ __('Bank kursları və yekun') }}</div>
            <div class="text-xs text-muted">{{ __('Bankın real kursu ilə CBAR arasındakı fərq (rubl satışı, avro alışı, logistika) və Trade-ə bağlı digər xərclər') }}</div>
            <div class="font-mono text-[11px] mt-1">BE = BT − AL − AM − {{ __('xərclər') }}</div></li>
    </ol>
    <p class="text-[11px] text-faint border-t border-line pt-3">{{ __('D — satıcının fakturası, H — alıcının proforması, öz valyutalarında. Ödənilməmiş hissələr bugünkü CBAR kursu ilə təxmini götürülür.') }}</p>
</aside>
