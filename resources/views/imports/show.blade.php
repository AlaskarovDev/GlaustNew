<x-layouts.app :title="'Import · '.$import->original_name">
    <x-page-header :title="$class::title().' importu'" :subtitle="$import->original_name.' · '.$import->total_rows.__(' sətir').($account ? ' · '.$account->name.' ('.$account->currency.')' : '')" :back="route('imports.index', ['type' => $import->type])"/>

    @if($import->status === 'uploaded')
        <ol class="flex items-center gap-3 mb-6 text-sm">
            <li class="flex items-center gap-2 text-muted"><span class="grid place-items-center size-6 rounded-full bg-success text-white"><x-icon name="check" class="size-3.5" :stroke="3"/></span> {{ __('Fayl yükləndi') }}</li>
            <li class="h-px w-8 bg-line-strong"></li>
            <li class="flex items-center gap-2 font-medium"><span class="grid place-items-center size-6 rounded-full bg-brand text-white text-xs">2</span> {{ __('Sütunları uyğunlaşdır və yoxla') }}</li>
            <li class="h-px w-8 bg-line-strong"></li>
            <li class="flex items-center gap-2 text-muted"><span class="grid place-items-center size-6 rounded-full bg-surface-2 text-xs">3</span> {{ __('Import') }}</li>
        </ol>

        <form method="GET" action="{{ route('imports.show', $import) }}" class="card p-6 mb-6" id="mapping-form">
            <input type="hidden" name="map" value="1">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                <div>
                    <h2 class="text-base font-semibold">{{ __('Sütunların uyğunlaşdırılması') }}</h2>
                    <p class="text-xs text-muted">{{ __('Sistem başlıqlara görə avtomatik seçib — yoxlayın və lazım olsa dəyişin.') }}</p>
                </div>
                <button class="btn btn-secondary btn-sm"><x-icon name="refresh" class="size-4"/> {{ __('Önizləməni yenilə') }}</button>
            </div>
            <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-x-5 gap-y-3">
                @foreach($fields as $key => $def)
                    <label class="flex items-center gap-3">
                        <span class="w-36 shrink-0 text-sm {{ ! empty($def['required']) ? 'font-medium' : 'text-ink-2' }}">{{ $def['label'] }}@if(! empty($def['required']))<span class="text-danger">*</span>@endif</span>
                        <select name="map[{{ $key }}]" class="input !h-9 text-[13px] {{ ! empty($def['required']) && ! isset($import->mapping[$key]) ? 'is-invalid' : '' }}">
                            <option value="">{{ __('— istifadə etmə —') }}</option>
                            @foreach($headers as $i => $h)
                                <option value="{{ $i }}" @selected(isset($import->mapping[$key]) && (int) $import->mapping[$key] === $i)>{{ $h !== null && $h !== '' ? $h : __('Sütun ').($i + 1) }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
        </form>

        @php $bad = collect($preview)->whereNotNull('error')->count(); @endphp
        <section class="card overflow-hidden mb-6">
            <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-line">
                <h2 class="text-sm font-semibold">{{ __('Önizləmə — ilk') }} {{ count($preview) }} {{ __('sətir') }}</h2>
                <div class="flex gap-2 text-xs">
                    <span class="badge badge-green">{{ count($preview) - $bad }} {{ __('düzgün') }}</span>
                    @if($bad)<span class="badge badge-rose">{{ $bad }} {{ __('xətalı') }}</span>@endif
                </div>
            </header>
            <div class="overflow-x-auto">
                <table class="table-g text-xs">
                    <thead><tr><th>{{ __('Sətir') }}</th><th>{{ __('Yoxlama') }}</th>@foreach($fields as $key => $def)@if(isset($import->mapping[$key]))<th>{{ $def['label'] }}</th>@endif @endforeach</tr></thead>
                    <tbody>
                    @foreach($preview as $p)
                        <tr @class(['bg-danger-soft/40' => $p['error']])>
                            <td class="font-mono">{{ $p['line'] }}</td>
                            <td class="min-w-[180px]">@if($p['error'])<span class="text-danger">{{ $p['error'] }}</span>@else<span class="text-success inline-flex items-center gap-1"><x-icon name="check" class="size-3.5"/> {{ __('hazırdır') }}</span>@endif</td>
                            @foreach($fields as $key => $def)
                                @if(isset($import->mapping[$key]))
                                    <td class="max-w-[200px] truncate">{{ is_scalar($p['values'][$key] ?? null) ? $p['values'][$key] : '' }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <form method="POST" action="{{ route('imports.run', $import) }}" class="card p-5 flex flex-col sm:flex-row sm:items-center gap-4" x-data="{ busy: false }" @submit="busy = true">
            @csrf
            @foreach($import->mapping ?? [] as $k => $v)
                @unless(str_starts_with($k, '__'))<input type="hidden" name="map[{{ $k }}]" value="{{ $v }}">@endunless
            @endforeach
            <div class="flex-1 text-sm">
                @if($missing->isNotEmpty())
                    <span class="text-danger flex items-center gap-2"><x-icon name="alert" class="size-4"/> {{ __('Məcburi sahələr uyğunlaşdırılmayıb:') }} {{ $missing->map(fn ($k) => $fields[$k]['label'])->implode(', ') }}</span>
                @else
                    {{ __('Yalnız xətasız sətirlər yazılacaq. Xətalı sətirlər səbəbi ilə ayrıca Excel faylında qaytarılacaq.') }}
                    @if($import->total_rows > \App\Imports\ImportRunner::SYNC_LIMIT)<span class="text-muted">{{ __('Fayl böyük olduğu üçün arxa planda emal olunacaq.') }}</span>@endif
                @endif
            </div>
            <button class="btn btn-primary" :disabled="busy" @disabled($missing->isNotEmpty())>
                <span x-show="busy" x-cloak class="size-4 rounded-full border-2 border-current border-t-transparent animate-spin"></span>
                {{ $import->total_rows }} {{ __('sətri import et') }}
            </button>
        </form>
    @else
        <section class="card p-8 max-w-2xl">
            @if(in_array($import->status, ['queued', 'processing']))
                <div class="flex items-center gap-4">
                    <span class="size-10 rounded-full border-4 border-brand border-t-transparent animate-spin"></span>
                    <div><div class="font-semibold">{{ __('Import davam edir…') }}</div><div class="text-sm text-muted">{{ $import->total_rows }} {{ __('sətir emal olunur. Səhifəni bir az sonra yeniləyin.') }}</div></div>
                </div>
            @elseif($import->status === 'failed')
                <x-empty icon="alert" :title="__('Import uğursuz oldu')" :text="$import->message"/>
            @else
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div><div class="text-3xl font-semibold font-mono">{{ $import->total_rows }}</div><div class="text-xs text-muted">{{ __('sətir') }}</div></div>
                    <div><div class="text-3xl font-semibold font-mono text-success">{{ $import->imported_rows }}</div><div class="text-xs text-muted">{{ __('import edildi') }}</div></div>
                    <div><div class="text-3xl font-semibold font-mono {{ $import->failed_rows ? 'text-danger' : '' }}">{{ $import->failed_rows }}</div><div class="text-xs text-muted">{{ __('xətalı') }}</div></div>
                </div>
                @if($import->error_path)
                    <div class="mt-6 flex flex-col sm:flex-row items-center gap-3 rounded-xl bg-danger-soft/50 border border-danger/20 p-4">
                        <x-icon name="sheet" class="size-6 text-danger"/>
                        <p class="text-sm flex-1">{{ __('Xətalı sətirlər «Xəta» sütunu ilə ayrıca faylda. Düzəldib yenidən yükləyə bilərsiniz.') }}</p>
                        <a href="{{ route('imports.errors', $import) }}" class="btn btn-secondary"><x-icon name="download" class="size-4"/> {{ __('Xətalar faylı') }}</a>
                    </div>
                @endif
                <div class="mt-6 flex gap-2"><a href="{{ route('imports.index', ['type' => $import->type]) }}" class="btn btn-primary">{{ __('Yeni import') }}</a></div>
            @endif
        </section>
    @endif
</x-layouts.app>
