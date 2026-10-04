{{-- Təsdiq axını of this invoice: submit, progress, decide, and the lock once it is in approval or approved. --}}
@php
    $svc = app(\App\Services\InvoiceApprovalService::class);
    $me = auth()->user();
    $flow = $invoice->approval_status ? ($invoice->approval_flow ?? []) : $svc->flow(tenant());
    $people = \App\Models\User::forTenant()->whereIn('id', array_column($flow, 'user_id'))->get()->keyBy('id');
    $log = $invoice->approvals()->with('user')->get();
    $blocker = $svc->blocker($invoice, tenant());
    $commercial = $invoice->salesDocuments->firstWhere('kind', 'commercial');
    $lastReject = $log->where('action', 'rejected')->last();
    $stepState = function (int $i) use ($invoice, $log) {
        if ($invoice->approval_status === 'approved') return 'done';
        if ($invoice->approval_status !== 'pending') return 'idle';
        return $i < $invoice->approval_step ? 'done' : ($i === $invoice->approval_step ? 'now' : 'idle');
    };
@endphp

@if($invoice->isApproved())
    <div class="lock-banner mb-6" role="alert">
        <span class="lock-pulse" aria-hidden="true"></span>
        <x-icon name="lock" class="size-5 shrink-0"/>
        <div class="flex-1 min-w-[220px]">
            <div class="font-semibold">{{ __('Fakturada düzəliş əməliyyatlarına icazə dayandırılıb') }}</div>
            <div class="text-xs opacity-80">Təsdiqlənib: {{ azdate($invoice->approved_at, true) }} — logistika, komissiya, konvertasiya və sənədlər artıq dəyişdirilmir.</div>
        </div>
        @if($commercial)
            <a href="{{ route('sales-documents.pdf', $commercial) }}" class="btn btn-primary"><x-icon name="download" class="size-4"/> Commercial Invoice {{ $commercial->number }}</a>
        @endif
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
            <h2 class="text-base font-semibold flex items-center gap-2"><x-icon name="check-circle" class="size-5 text-brand"/> {{ __('Təsdiq') }}</h2>
            <p class="text-xs text-muted">{{ __('Hesablama və proforma hazır olandan sonra faktura təsdiq axınına göndərilir') }}</p>
        </div>
        @if($invoice->approval_status)
            @php [$stLabel, $stTone] = \App\Models\Invoice::APPROVAL_STATUSES[$invoice->approval_status]; @endphp
            <span class="badge badge-{{ $stTone }}">{{ $stLabel }}</span>
        @endif
    </header>

    <div class="p-5 space-y-5">
        @if($flow)
            <ol class="approval-steps" aria-label="{{ __('Təsdiq addımları') }}">
                @foreach($flow as $i => $s)
                    @php $state = $stepState($i); $who = $people[$s['user_id']] ?? null; @endphp
                    <li class="approval-step is-{{ $state }}">
                        <span class="approval-dot">@if($state === 'done')<x-icon name="check" class="size-4"/>@else{{ $i + 1 }}@endif</span>
                        <div class="min-w-0">
                            <div class="font-medium truncate">{{ $who?->name ?? 'Silinmiş istifadəçi' }}</div>
                            <div class="text-[11px] text-muted truncate">{{ $s['title'] ?: ($who?->position ?? '') }}{{ $state === 'now' ? ' · gözləyir' : '' }}</div>
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
                    <form method="POST" action="{{ route('invoices.approval.submit', $invoice) }}">@csrf
                        <button class="btn btn-primary" @disabled($blocker)><x-icon name="send" class="size-4"/> {{ __('Fakturanı təsdiqə göndər') }}</button>
                    </form>
                    @if($blocker)
                        <span class="text-sm text-muted">{{ $blocker }}
                            @if(! $flow && auth()->user()->can('settings.view'))<a href="{{ route('settings.approvals') }}" class="text-brand-ink underline">{{ __('Axını qur') }}</a>@endif
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
                        <span class="font-medium">{{ $e->user?->name ?? 'Sistem' }}</span>
                        <span class="badge badge-{{ $tone }} !h-5">{{ $verb }}</span>
                        <span class="text-xs text-faint">{{ azdate($e->created_at, true) }}</span>
                        @if($e->comment)<span class="w-full text-xs text-muted pl-1">«{{ $e->comment }}»</span>@endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</section>
