{{-- $history: AuditLog collection for one record --}}
@php
    $verbs = ['created' => ['yaratdı', 'green'], 'updated' => ['dəyişdi', 'blue'], 'deleted' => ['sildi', 'rose'], 'restored' => ['bərpa etdi', 'teal']];
    $show = function ($v) {
        if (is_bool($v)) return $v ? 'bəli' : 'xeyr';
        if ($v === null || $v === '') return '—';
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
        return \Illuminate\Support\Str::limit((string) $v, 60);
    };
@endphp
<section class="card overflow-hidden" x-data="{ open: false }">
    <button type="button" class="w-full flex items-center justify-between px-5 h-14 text-left" @click="open = !open" :aria-expanded="open">
        <h2 class="text-sm font-semibold flex items-center gap-2"><x-icon name="history" class="size-4 text-muted"/> Dəyişiklik tarixçəsi <span class="text-muted font-mono font-normal">{{ $history->count() }}</span></h2>
        <x-icon name="chevron-down" class="size-4 text-muted transition-transform" ::class="open && 'rotate-180'"/>
    </button>
    <div x-show="open" x-collapse x-cloak>
        <ol class="border-t border-line px-5 py-4 space-y-4">
            @forelse($history as $h)
                <li class="flex gap-3">
                    <x-avatar :user="$h->user" size="sm"/>
                    <div class="min-w-0 text-sm flex-1">
                        <div><span class="font-medium">{{ $h->user?->name ?? 'Sistem' }}</span>
                            <span class="badge badge-{{ $verbs[$h->action][1] ?? 'slate' }} !h-5 mx-1">{{ $verbs[$h->action][0] ?? $h->action }}</span>
                            <span class="text-xs text-faint">{{ azdate($h->created_at, true) }}</span></div>
                        @if($h->action === 'updated' && $h->new_values)
                            <dl class="mt-1.5 text-xs space-y-0.5">
                                @foreach($h->new_values as $field => $new)
                                    <div class="flex flex-wrap gap-x-1.5"><dt class="text-muted">{{ $field }}:</dt>
                                        <dd><span class="line-through text-faint">{{ $show($h->old_values[$field] ?? null) }}</span> → <span class="text-ink-2">{{ $show($new) }}</span></dd></div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                </li>
            @empty
                <li class="text-sm text-muted">Tarixçə yoxdur.</li>
            @endforelse
        </ol>
    </div>
</section>
