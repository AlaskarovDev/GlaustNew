{{-- $model (HasAttachments), $type (morph alias), $ability (permission to upload/delete) --}}
<section class="card p-5" x-data="{ name: '' }">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold flex items-center gap-2"><x-icon name="paperclip" class="size-4 text-muted"/> {{ __('Fayllar') }} <span class="text-muted font-mono font-normal">{{ $model->attachments->count() }}</span></h2>
    </div>
    <ul class="space-y-1.5">
        @forelse($model->attachments as $file)
            <li class="flex items-center gap-2.5 p-2 rounded-lg hover:bg-surface-2 group">
                <span class="grid place-items-center size-8 rounded-md bg-surface-2 text-muted text-[10px] font-semibold uppercase shrink-0">{{ \Illuminate\Support\Str::of($file->original_name)->afterLast('.')->limit(4, '') }}</span>
                <a href="{{ route('attachments.download', $file) }}" class="min-w-0 flex-1">
                    <span class="block text-sm truncate group-hover:text-brand-ink">{{ $file->original_name }}</span>
                    <span class="block text-[11px] text-muted">{{ $file->humanSize() }} · {{ azdate($file->created_at) }} · {{ $file->uploader?->name }}</span>
                </a>
                @can($ability)
                    <x-delete-form :action="route('attachments.destroy', $file)" label="" :message="'«'.$file->original_name.'» silinsin?'" :button="__('btn btn-ghost btn-sm btn-icon text-faint hover:text-danger')"/>
                @endcan
            </li>
        @empty
            <li class="text-sm text-muted">{{ __('Fayl yoxdur.') }}</li>
        @endforelse
    </ul>
    @can($ability)
        <form method="POST" action="{{ route('attachments.store') }}" enctype="multipart/form-data" class="mt-3">
            @csrf
            <input type="hidden" name="attachable_type" value="{{ $type }}">
            <input type="hidden" name="attachable_id" value="{{ $model->id }}">
            <label class="flex flex-col items-center justify-center gap-1 h-24 rounded-xl border-2 border-dashed border-line hover:border-brand hover:bg-brand-soft/40 cursor-pointer transition-colors text-center px-3">
                <x-icon name="upload" class="size-5 text-muted"/>
                <span class="text-xs text-muted" x-text="name || 'Fayl seçin (PDF, şəkil, Word, Excel · 15 MB-a qədər)'"></span>
                <input type="file" name="file" class="sr-only" required @change="name = $event.target.files[0]?.name; $nextTick(() => $el.form.requestSubmit())">
            </label>
            @error('file')<p class="field-error">{{ $message }}</p>@enderror
        </form>
    @endcan
</section>
