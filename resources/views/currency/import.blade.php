<x-layouts.app :title="__('CBAR kursları importu')">
    <x-page-header :title="__('CBAR kursları importu')" icon="upload" :subtitle="__('Mərkəzi Bankdan kurs gəlməyəndə: bülleteni (XML), Excel siyahısını yükləyin və ya kursu özünüz yazın')">
        <x-slot:actions>
            <a href="{{ route('currency.index') }}" class="btn btn-secondary"><x-icon name="coins" class="size-4"/> {{ __('Valyuta məzənnələri') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card border-saffron/40 bg-saffron-soft/50 p-4 mb-6 flex items-start gap-3 text-sm">
        <x-icon name="info" class="size-5 text-saffron shrink-0"/>
        <div>{{ __('Bu kurslar bütün sistem üçündür. CBAR-ın öz kursu olan günə toxunulmur; CBAR həmin gün üçün yenidən cavab verəndə onun bülleteni buradakı kursu avtomatik əvəz edir. Kursu kimin yazdığı saxlanılır.') }}</div>
    </div>

    <div class="grid lg:grid-cols-3 gap-6 mb-6">
        {{-- 1. the bulletin itself --}}
        <form method="POST" action="{{ route('currency.import.xml') }}" enctype="multipart/form-data" class="card p-5 space-y-3">
            @csrf
            <h2 class="text-base font-semibold">1. {{ __('CBAR bülleteni (XML)') }}</h2>
            <p class="text-[12px] text-muted leading-relaxed">{{ __('cbar.az-dan günün faylını yükləyin: https://www.cbar.az/currencies/GG.AA.İİİİ.xml (məs. 11.03.2025.xml). Bir neçə günün faylını birdən seçmək olar — bütün valyutalar yazılır.') }}</p>
            <input type="file" name="files[]" accept=".xml,text/xml,application/xml" multiple required class="input @error('files') is-invalid @enderror">
            @error('files')<span class="block text-xs text-danger">{{ $message }}</span>@enderror
            <button class="btn btn-primary"><x-icon name="upload" class="size-4"/> {{ __('Bülleteni yüklə') }}</button>
        </form>

        {{-- 2. an Excel list --}}
        <form method="POST" action="{{ route('currency.import.excel') }}" enctype="multipart/form-data" class="card p-5 space-y-3">
            @csrf
            <div class="flex items-start justify-between gap-2">
                <h2 class="text-base font-semibold">2. {{ __('Excel ilə') }}</h2>
                <a href="{{ route('currency.import.template') }}" class="btn btn-secondary btn-sm"><x-icon name="download" class="size-4"/> {{ __('Şablon') }}</a>
            </div>
            <p class="text-[12px] text-muted leading-relaxed">{{ __('Sütunlar: Tarix · Valyuta · Məzənnə (nominal üçün AZN) · Nominal. Məs. 11.03.2025 · RUB · 1,9551 · 100 (100 rubl = 1,9551 AZN).') }}</p>
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required class="input @error('file') is-invalid @enderror">
            @error('file')<span class="block text-xs text-danger">{{ $message }}</span>@enderror
            <button class="btn btn-primary"><x-icon name="sheet" class="size-4"/> {{ __('Exceli yüklə') }}</button>
        </form>

        {{-- 3. one rate by hand --}}
        <form method="POST" action="{{ route('currency.import.store') }}" class="card p-5 space-y-3">
            @csrf
            <h2 class="text-base font-semibold">3. {{ __('Əl ilə') }}</h2>
            <div class="grid grid-cols-2 gap-3">
                <label><span class="field-label">{{ __('Tarix') }}</span><input type="date" name="rate_date" value="{{ old('rate_date') }}" max="{{ today()->toDateString() }}" required class="input @error('rate_date') is-invalid @enderror"></label>
                <label><span class="field-label">{{ __('Valyuta') }}</span><input name="currency_code" value="{{ old('currency_code', 'EUR') }}" maxlength="3" required class="input font-mono uppercase @error('currency_code') is-invalid @enderror"></label>
                <label><span class="field-label">{{ __('Məzənnə (AZN)') }}</span><input name="rate" value="{{ old('rate') }}" inputmode="decimal" required placeholder="1,8435" class="input font-mono @error('rate') is-invalid @enderror"></label>
                <label><span class="field-label">{{ __('Nominal') }}</span><input name="nominal" value="{{ old('nominal', '1') }}" inputmode="decimal" class="input font-mono"></label>
            </div>
            @foreach(['rate_date', 'currency_code', 'rate'] as $f)@error($f)<span class="block text-xs text-danger">{{ $message }}</span>@enderror @endforeach
            <button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button>
        </form>
    </div>

    <section class="card overflow-hidden">
        <header class="px-5 py-4 border-b border-line"><h2 class="text-base font-semibold">{{ __('Əl ilə gətirilmiş kurslar') }}</h2>
            <p class="text-xs text-muted">{{ __('CBAR cavab verəndə bu sətirlər onun öz kursu ilə əvəz olunur və siyahıdan çıxır.') }}</p></header>
        @if($recent->isEmpty())
            <x-empty icon="coins" :title="__('Hələ əl ilə kurs yoxdur')" :text="__('Bütün kurslar Mərkəzi Bankdan gəlib.')"/>
        @else
            <div class="overflow-x-auto">
                <table class="table-g table-stack text-[13px]">
                    <thead><tr><th>{{ __('Tarix') }}</th><th>{{ __('Valyuta') }}</th><th class="!text-right">{{ __('1 vahid = AZN') }}</th><th class="!text-right">{{ __('Nominal') }}</th><th>{{ __('Mənbə') }}</th><th>{{ __('Yazıb') }}</th><th class="w-12"></th></tr></thead>
                    <tbody>
                    @foreach($recent as $r)
                        <tr>
                            <td data-label="{{ __('Tarix') }}" class="font-mono text-xs">{{ azdate($r->rate_date) }}</td>
                            <td data-label="{{ __('Valyuta') }}" class="font-mono font-medium">{{ $r->currency_code }}</td>
                            <td data-label="{{ __('1 vahid = AZN') }}" class="num">{{ rate_fmt((float) $r->rate) }}</td>
                            <td data-label="{{ __('Nominal') }}" class="num text-muted">{{ (float) $r->nominal }}</td>
                            <td data-label="{{ __('Mənbə') }}"><span class="badge badge-amber">{{ $r->source }}</span></td>
                            <td data-label="{{ __('Yazıb') }}" class="text-xs text-muted">{{ $r->creator?->name ?? '—' }}<div>{{ $r->updated_at?->format('d.m.Y H:i') }}</div></td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('currency.import.destroy', $r) }}" data-confirm="{{ __('Bu kurs silinsin?') }}" data-confirm-action="{{ __('Sil') }}">
                                    @csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm text-danger" aria-label="{{ __('Sil') }}"><x-icon name="trash" class="size-4"/></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
