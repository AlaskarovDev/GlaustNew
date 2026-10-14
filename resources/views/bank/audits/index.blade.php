<x-layouts.app :title="__('Bank hesabları auditi')" wide>
    <x-page-header :title="__('Bank hesabları auditi')" icon="list-checks" :subtitle="__('Bankın çıxarışını yükləyin — sistemdəki əməliyyatlarla sətir-sətir tutuşdurulur, fərqlər bank çıxarışındakı kimi düzəldilir')"/>
    @include('bank._tabs')

    @can('bank.import')
        <form method="POST" action="{{ route('bank.audits.store') }}" enctype="multipart/form-data" class="card p-5 mb-6">
            @csrf
            <div class="flex flex-wrap items-end gap-4">
                <label class="flex-1 min-w-[260px]">
                    <span class="field-label">{{ __('Bank çıxarışı (Excel)') }}</span>
                    <input type="file" name="file" accept=".xlsx,.xls" required class="input @error('file') is-invalid @enderror">
                    @error('file')<span class="block text-xs text-danger mt-1">{{ $message }}</span>@enderror
                </label>
                <label class="min-w-[260px]">
                    <span class="field-label">{{ __('Hesab') }}</span>
                    <select name="bank_account_id" class="input @error('bank_account_id') is-invalid @enderror">
                        <option value="">{{ __('IBAN-a görə avtomatik') }}</option>
                        @foreach($accounts as $a)<option value="{{ $a->id }}" @selected((int) old('bank_account_id') === $a->id)>{{ $a->bank_name }} · {{ $a->name }} ({{ $a->currency }}){{ $a->iban ? ' · '.$a->iban : '' }}</option>@endforeach
                    </select>
                    @error('bank_account_id')<span class="block text-xs text-danger mt-1">{{ $message }}</span>@enderror
                </label>
                <button class="btn btn-primary"><x-icon name="upload" class="size-4"/> {{ __('Yüklə və yoxla') }}</button>
            </div>
            <p class="text-[11px] text-muted mt-3 leading-relaxed">{{ __('Bankın «Hesabdan çıxarış / Account statement» Excel faylı olduğu kimi: başlıqda IBAN, valyuta, dövr və qalıqlar, cədvəldə № · Tarix · Hesab nömrəsi · Məbləğ · Məbləğin AZN dəyəri · Qalıq · Qalığın AZN dəyəri · Valyuta · Təyinat. Hesab IBAN-a görə tapılır; tapılmasa seçdiyiniz hesaba IBAN yazılır.') }}</p>
        </form>
    @endcan

    <section class="card overflow-hidden">
        <header class="px-5 py-4 border-b border-line"><h2 class="text-base font-semibold">{{ __('Yüklənmiş çıxarışlar') }}</h2></header>
        @if($audits->isEmpty())
            <x-empty icon="list-checks" :title="__('Hələ çıxarış yüklənməyib')" :text="__('Yuxarıdan bankın Excel çıxarışını yükləyin.')"/>
        @else
            <div class="overflow-x-auto">
                <table class="table-g table-stack text-[13px]">
                    <thead><tr><th>{{ __('Hesab') }}</th><th>{{ __('Dövr') }}</th><th>{{ __('Fayl') }}</th><th class="!text-right">{{ __('Sətir') }}</th><th>{{ __('Vəziyyət') }}</th><th>{{ __('Yükləyib') }}</th><th class="w-24"></th></tr></thead>
                    <tbody>
                    @foreach($audits as $a)
                        <tr>
                            <td data-label="{{ __('Hesab') }}"><a href="{{ route('bank.audits.show', $a) }}" class="font-medium hover:text-brand-ink">{{ $a->account?->name }}</a>
                                <div class="text-[11px] text-muted font-mono">{{ $a->currency }}{{ $a->iban ? ' · '.$a->iban : '' }}</div></td>
                            <td data-label="{{ __('Dövr') }}" class="font-mono text-xs whitespace-nowrap">{{ azdate($a->period_from) }} — {{ azdate($a->period_to) }}</td>
                            <td data-label="{{ __('Fayl') }}" class="text-xs text-muted">{{ $a->file_name }}</td>
                            <td data-label="{{ __('Sətir') }}" class="num">{{ $a->lines_count }}</td>
                            <td data-label="{{ __('Vəziyyət') }}">
                                @if($issues[$a->id])<span class="badge badge-rose">{{ $issues[$a->id] }} {{ __('problem') }}</span>
                                @else<span class="badge badge-green">{{ __('Tam uyğun') }}</span>@endif
                            </td>
                            <td data-label="{{ __('Yükləyib') }}" class="text-xs text-muted">{{ $a->creator?->name }}<div>{{ $a->created_at->format('d.m.Y H:i') }}</div></td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('bank.audits.show', $a) }}" class="btn btn-secondary btn-sm">{{ __('Aç') }}</a>
                                @can('bank.import')
                                    <form method="POST" action="{{ route('bank.audits.destroy', $a) }}" class="inline" data-confirm="{{ __('Çıxarış silinsin? Sistemdəki əməliyyatlara toxunulmayacaq.') }}" data-confirm-action="{{ __('Sil') }}">
                                        @csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm text-danger" aria-label="{{ __('Sil') }}"><x-icon name="trash" class="size-4"/></button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
