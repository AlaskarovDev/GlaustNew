{{-- Approval of this invoice. With the approval chain off (default): approve & lock in one step, unlock for a
     while with a reason, then re-form and lock again. With the chain on: submit, progress, decide. --}}
@php
    $svc = app(\App\Services\InvoiceApprovalService::class);
    $flowOn = \App\Services\InvoiceApprovalService::flowEnabled();
    $me = auth()->user();
    $flow = in_array($invoice->approval_status, ['pending', 'approved', 'rejected'], true) ? ($invoice->approval_flow ?? []) : ($flowOn ? $svc->flow(tenant()) : []);
    $people = \App\Models\User::forTenant()->whereIn('id', array_column($flow, 'user_id'))->get()->keyBy('id');
    $log = $invoice->approvals()->with('user')->get();
    $blocker = $svc->blocker($invoice, tenant());
    $commercial = $invoice->salesDocuments->firstWhere('kind', 'commercial');
    $lastReject = $log->where('action', 'rejected')->last();
    $lastUnlock = $log->where('action', 'unlocked')->last();
    $builder = app(\App\Support\Invoices\SalesDocumentBuilder::class);
    $stale = $invoice->approval_status === 'unlocked'
        ? $invoice->salesDocuments->whereIn('kind', \App\Models\SalesDocument::AUTO_KINDS)->filter(fn ($d) => $builder->isStale($d))
        : collect();
    $stepState = function (int $i) use ($invoice) {
        if ($invoice->approval_status === 'approved') return 'done';
        if ($invoice->approval_status !== 'pending') return 'idle';
        return $i < $invoice->approval_step ? 'done' : ($i === $invoice->approval_step ? 'now' : 'idle');
    };
@endphp

@if($invoice->isApproved())
    <div class="lock-banner mb-6" role="alert" x-data="{ unlocking: {{ $errors->has('reason') ? 'true' : 'false' }} }">
        <span class="lock-pulse" aria-hidden="true"></span>
        <x-icon name="lock" class="size-5 shrink-0"/>
        <div class="flex-1 min-w-[220px]">
            <div class="font-semibold">{{ __('Fakturada düzəliş əməliyyatlarına icazə dayandırılıb') }}</div>
            <div class="text-xs opacity-80">{{ __('Təsdiqlənib:') }} {{ azdate($invoice->approved_at, true) }} {{ __('— logistika, komissiya, konvertasiya və sənədlər artıq dəyişdirilmir.') }}</div>
        </div>
        @if($commercial)
            <a href="{{ route('sales-documents.pdf', $commercial) }}" class="btn btn-primary"><x-icon name="download" class="size-4"/> Commercial Invoice {{ $commercial->number }}</a>
        @endif
        @if(! $flowOn)
            @can('projects.update')
                <button type="button" class="btn btn-secondary" @click="unlocking = true"><x-icon name="key" class="size-4"/> {{ __('Müvəqqəti kilidi aç') }}</button>
                <template x-teleport="body">
                    <div x-cloak x-show="unlocking" class="fixed inset-0 z-[75] grid place-items-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="unlocking = false">
                        <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="unlocking = false"></div>
                        <form method="POST" action="{{ route('invoices.approval.unlock', $invoice) }}" class="relative w-full max-w-lg card p-6 space-y-4 !shadow-[var(--shadow-pop)]" x-trap.noscroll="unlocking">
                            @csrf
                            <div>
                                <h2 class="text-lg font-semibold">{{ __('Fakturanı müvəqqəti kiliddən çıxar') }}</h2>
                                <p class="text-sm text-muted">{{ __('Səbəb yadda saxlanılır. Düzəlişdən sonra faktura yenidən formalaşdırılıb kilidlənir; Commercial Invoice yenilənir və məbləğ fərqi qeydə alınır.') }}</p>
                            </div>
                            <x-field :label="__('Səbəb')" name="reason" required>
                                <textarea name="reason" rows="3" class="input" required minlength="3" maxlength="500" placeholder="{{ __('Məs: alıcı miqdarı dəyişdi, logistika aktı fərqli gəldi') }}">{{ old('reason') }}</textarea>
                            </x-field>
                            <div class="flex justify-end gap-2">
                                <button type="button" class="btn btn-secondary" @click="unlocking = false">{{ __('Bağla') }}</button>
                                <button class="btn btn-primary"><x-icon name="key" class="size-4"/> {{ __('Kilidi aç və redaktə et') }}</button>
                            </div>
                        </form>
                    </div>
                </template>
            @endcan
        @endif
    </div>
@elseif($invoice->approval_status === 'unlocked')
    <div class="rounded-xl border border-saffron/50 bg-saffron-soft/60 px-4 py-3 mb-6 flex flex-wrap items-center gap-3 text-sm" role="status">
        <x-icon name="key" class="size-5 text-saffron shrink-0"/>
        <div class="flex-1 min-w-[220px]">
            <b>{{ __('Faktura müvəqqəti kiliddən çıxarılıb.') }}</b>
            @if($lastUnlock) {{ __('Səbəb:') }} «{{ $lastUnlock->comment }}» — {{ $lastUnlock->user?->name }}, {{ azdate($lastUnlock->created_at, true) }}.@endif
            <div class="text-xs mt-0.5">{{ __('Logistika, komissiya, konvertasiya və sənədlərdə düzəliş edin, sonra yenidən formalaşdırın.') }}</div>
            @foreach($stale as $doc)
                <div class="text-xs mt-1 text-danger">{{ __(':doc fakturanın yeni hesablamasından fərqlənir —', ['doc' => $doc->title().' '.$doc->number]) }}
                    <a href="{{ route('sales-documents.show', $doc) }}" class="underline font-medium">{{ __('sənədi açıb yeniləyin') }}</a></div>
            @endforeach
        </div>
        @can('projects.update')
            <form method="POST" action="{{ route('invoices.approval.finalize', $invoice) }}" data-confirm="{{ __('Faktura yenidən formalaşdırılıb kilidlənsin? Commercial Invoice sənədlərin cari halına görə yenilənəcək.') }}" data-confirm-action="{{ __('Formalaşdır') }}">
                @csrf <button class="btn btn-primary"><x-icon name="lock" class="size-4"/> {{ __('Yenidən formalaşdır və kilidlə') }}</button>
            </form>
        @endcan
    </div>
@elseif($invoice->approval_status === 'pending')
    <div class="rounded-xl border border-saffron/50 bg-saffron-soft/60 px-4 py-3 mb-6 flex flex-wrap items-center gap-3 text-sm" role="status">
        <x-icon name="clock" class="size-5 text-saffron shrink-0"/>
        <div class="flex-1 min-w-[220px]"><b>{{ __('Faktura təsdiqdədir.') }}</b> {{ __('Təsdiq bitənə qədər fakturada və sənədlərində düzəlişlər dayandırılıb.') }}</div>
    </div>
@endif

<section class="card mb-6 overflow-hidden" id="approval">
    <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-line">
        <div>
            <h2 class="text-base font-semibold flex items-center gap-2"><x-icon name="check-circle" class="size-5 text-brand"/> {{ $flowOn ? __('Təsdiq') : __('Təsdiq və kilid') }}</h2>
            <p class="text-xs text-muted">{{ $flowOn ? __('Hesablama və proforma hazır olandan sonra faktura təsdiq axınına göndərilir') : __('Hesablama və proforma hazır olandan sonra faktura təsdiqlənib kilidlənir — Commercial Invoice yaranır') }}</p>
        </div>
        @if($invoice->approval_status)
            @php [$stLabel, $stTone] = \App\Models\Invoice::APPROVAL_STATUSES[$invoice->approval_status]; @endphp
            <span class="badge badge-{{ $stTone }}">{{ __($stLabel) }}</span>
        @endif
    </header>

    <div class="p-5 space-y-5">
        @if($flowOn && $flow)
            <ol class="approval-steps" aria-label="{{ __('Təsdiq addımları') }}">
                @foreach($flow as $i => $s)
                    @php $state = $stepState($i); $who = $people[$s['user_id']] ?? null; @endphp
                    <li class="approval-step is-{{ $state }}">
                        <span class="approval-dot">@if($state === 'done')<x-icon name="check" class="size-4"/>@else{{ $i + 1 }}@endif</span>
                        <div class="min-w-0">
                            <div class="font-medium truncate">{{ $who?->name ?? __('Silinmiş istifadəçi') }}</div>
                            <div class="text-[11px] text-muted truncate">{{ $s['title'] ?: ($who?->position ?? '') }}{{ $state === 'now' ? __(' · gözləyir') : '' }}</div>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- Current approver decides --}}
        @if($invoice->currentApproverId() === $me->id)
            <form method="POST" action="{{ route('invoices.approval.decide', $invoice) }}" class="rounded-xl border border-brand/30 bg-brand-soft/40 p-4 space-y-3" x-data="{ comment: '' }">
                @csrf
                <div class="text-sm font-semibold">{{ __('Sizin qərarınız gözlənilir') }}</div>
                <textarea name="comment" x-model="comment" rows="2" class="input" placeholder="{{ __('Şərh (geri qaytaranda mütləqdir)') }}"></textarea>
                @error('comment')<p class="field-error">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-2">
                    <button name="decision" value="approve" class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Təsdiqlə') }}</button>
                    <button name="decision" value="reject" class="btn btn-secondary text-danger" :disabled="!comment.trim()" title="{{ __('Səbəb yazın') }}"><x-icon name="x" class="size-4"/> {{ __('Geri qaytar') }}</button>
                </div>
            </form>
        @endif

        @error('approval')<p class="field-error">{{ $message }}</p>@enderror

        @if(in_array($invoice->approval_status, [null, 'rejected'], true))
            @if($lastReject && $invoice->approval_status === 'rejected')
                <div class="rounded-xl border border-danger/30 bg-danger-soft/40 px-4 py-3 text-sm">
                    <b class="text-danger">{{ __('Geri qaytarılıb') }}</b> — {{ $lastReject->user?->name }}, {{ azdate($lastReject->created_at, true) }}:
                    <span class="text-ink">{{ $lastReject->comment }}</span>
                </div>
            @endif
            @can('projects.update')
                <div class="flex flex-wrap items-center gap-3">
                    @if($flowOn)
                        <form method="POST" action="{{ route('invoices.approval.submit', $invoice) }}">@csrf
                            <button class="btn btn-primary" @disabled($blocker)><x-icon name="send" class="size-4"/> {{ __('Fakturanı təsdiqə göndər') }}</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('invoices.approval.finalize', $invoice) }}" data-confirm="{{ __('Faktura təsdiqlənib kilidlənsin? Commercial Invoice yaradılacaq.') }}" data-confirm-action="{{ __('Təsdiqlə') }}">@csrf
                            <button class="btn btn-primary" @disabled($blocker)><x-icon name="lock" class="size-4"/> {{ __('Fakturanı təsdiqlə və kilidlə') }}</button>
                        </form>
                    @endif
                    @if($blocker)
                        <span class="text-sm text-muted">{{ $blocker }}
                            @if($flowOn && ! $flow && auth()->user()->can('settings.view'))<a href="{{ route('settings.approvals') }}" class="text-brand-ink underline">{{ __('Axını qur') }}</a>@endif
                        </span>
                    @endif
                </div>
            @endcan
        @elseif($invoice->approval_status === 'pending' && ($invoice->submitted_by === $me->id || $me->role?->is_admin))
            <form method="POST" action="{{ route('invoices.approval.withdraw', $invoice) }}" data-confirm="{{ __('Təsdiq sorğusu geri çəkilsin? Faktura yenidən redaktə edilə biləcək.') }}" data-confirm-action="{{ __('Geri çək') }}">
                @csrf <button class="btn btn-ghost btn-sm"><x-icon name="arrow-left" class="size-4"/> {{ __('Sorğunu geri çək') }}</button>
            </form>
        @endif

        @if($log->isNotEmpty())
            <ol class="border-t border-line pt-4 space-y-2 text-sm">
                @foreach($log->reverse() as $e)
                    @php [$verb, $tone] = \App\Models\InvoiceApproval::ACTIONS[$e->action] ?? [$e->action, 'slate']; @endphp
                    <li class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-medium">{{ $e->user?->name ?? __('Sistem') }}</span>
                        <span class="badge badge-{{ $tone }} !h-5">{{ __($verb) }}</span>
                        <span class="text-xs text-faint">{{ azdate($e->created_at, true) }}</span>
                        @if($e->comment)<span class="w-full text-xs text-muted pl-1">«{{ $e->comment }}»</span>@endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</section>
