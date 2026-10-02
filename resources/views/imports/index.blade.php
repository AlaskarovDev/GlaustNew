<x-layouts.app title="Excel import">
    <x-page-header title="Excel import" icon="upload" subtitle="Şablonu yükləyin, doldurun, faylı seçin — sütunları yoxlayıb təsdiqləyəcəksiniz"/>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] gap-6 items-start">
        <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" class="card p-6 space-y-5"
              x-data="{ type: @js(old('type', $selected)), file: '' }">
            @csrf
            <fieldset>
                <legend class="field-label">Nəyi import edirsiniz?</legend>
                <div class="grid sm:grid-cols-2 gap-2">
                    @foreach($types as $t)
                        <label class="flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all"
                               :class="type === '{{ $t::type() }}' ? 'border-brand bg-brand-soft/60 ring-1 ring-brand/30' : 'border-line hover:border-line-strong'">
                            <input type="radio" name="type" value="{{ $t::type() }}" x-model="type" class="sr-only">
                            <span><span class="block text-sm font-medium">{{ $t::title() }}</span><span class="block text-xs text-muted mt-0.5">{{ $t::description() }}</span></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div x-show="type === 'bank_transactions'" x-cloak>
                <x-select name="account_id" label="Bank hesabı" :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->name.' · '.$a->bank_name.' ('.$a->currency.')'])->all()" placeholder="— seçin —"/>
            </div>

            <div>
                <label class="flex flex-col items-center justify-center gap-2 h-40 rounded-2xl border-2 border-dashed cursor-pointer transition-colors text-center px-4"
                       :class="file ? 'border-brand bg-brand-soft/40' : 'border-line hover:border-brand hover:bg-brand-soft/30'">
                    <x-icon name="sheet" class="size-8 text-brand"/>
                    <span class="text-sm font-medium" x-text="file || 'Excel və ya CSV faylını seçin'"></span>
                    <span class="text-xs text-muted">.xlsx, .xls, .csv · 10 MB-a qədər · ilk sətir başlıqdır</span>
                    <input type="file" name="file" accept=".xlsx,.xls,.csv" class="sr-only" required @change="file = $event.target.files[0]?.name">
                </label>
                @error('file')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <a :href="@js(route('imports.template', '__T__')).replace('__T__', type)" class="btn btn-ghost text-brand-ink"><x-icon name="download" class="size-4"/> Nümunə şablonu yüklə</a>
                <button class="btn btn-primary"><x-icon name="arrow-right" class="size-4"/> Davam et</button>
            </div>
        </form>

        <section class="card overflow-hidden">
            <header class="px-5 h-14 flex items-center border-b border-line"><h2 class="text-sm font-semibold">Import tarixçəsi</h2></header>
            @if($history->isEmpty())
                <x-empty icon="history" title="Hələ import edilməyib" class="!py-10"/>
            @else
                <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead><tr><th>Fayl</th><th>Növ</th><th>Nəticə</th><th>Tarix</th></tr></thead>
                    <tbody>
                    @foreach($history as $i)
                        <tr>
                            <td data-label="Fayl"><a href="{{ route('imports.show', $i) }}" class="font-medium hover:text-brand-ink break-all">{{ $i->original_name }}</a><div class="text-xs text-muted">{{ $i->user?->name }}</div></td>
                            <td data-label="Növ" class="text-xs">{{ collect(\App\Imports\ImportRunner::TYPES)->first(fn ($c) => $c::type() === $i->type)::title() }}</td>
                            <td data-label="Nəticə">
                                @switch($i->status)
                                    @case('done')<span class="badge badge-green">{{ $i->imported_rows }} ✓</span>@if($i->failed_rows)<span class="badge badge-rose ml-1">{{ $i->failed_rows }} ✗</span>@endif @break
                                    @case('queued') @case('processing')<span class="badge badge-blue">Gedir…</span> @break
                                    @case('failed')<span class="badge badge-rose">Xəta</span> @break
                                    @default<span class="badge badge-slate">Təsdiq gözləyir</span>
                                @endswitch
                            </td>
                            <td data-label="Tarix" class="font-mono text-xs">{{ azdate($i->created_at, true) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>
