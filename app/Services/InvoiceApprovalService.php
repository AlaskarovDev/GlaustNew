<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\Reminder;
use App\Models\User;
use App\Support\Invoices\SalesDocumentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Təsdiq axını of a calculated seller invoice: the approvers configured in Settings decide one
 * after another. Each approver gets a notification (header bell + e-mail through the reminder
 * mailer). Final approval locks the invoice and issues the commercial invoice to the buyer;
 * a rejection sends it back to the submitter and unlocks it.
 */
class InvoiceApprovalService
{
    public function __construct(private SalesDocumentBuilder $documents) {}

    /** @return list<array{user_id: int, title: string}> configured steps whose user is active in this company */
    public function flow(Company $company): array
    {
        $steps = array_values(array_filter((array) $company->setting('approval_flow', []), fn ($s) => ! empty($s['user_id'])));
        $active = User::forTenant()->where('is_active', true)->whereIn('id', array_column($steps, 'user_id'))->pluck('id')->all();

        return array_values(array_map(
            fn ($s) => ['user_id' => (int) $s['user_id'], 'title' => trim((string) ($s['title'] ?? ''))],
            array_filter($steps, fn ($s) => in_array((int) $s['user_id'], $active, true)),
        ));
    }

    /** Why the invoice cannot be submitted now, or null when it can. */
    public function blocker(Invoice $invoice, Company $company): ?string
    {
        return match (true) {
            $invoice->type !== 'supplier' => 'Yalnız satıcının fakturası təsdiqə göndərilir.',
            $invoice->approval_status === 'pending' => 'Faktura artıq təsdiqdədir.',
            $invoice->approval_status === 'approved' => 'Faktura artıq təsdiqlənib.',
            $invoice->status === 'cancelled' => 'Ləğv edilmiş faktura təsdiqə göndərilmir.',
            ! $invoice->rubReady() => 'Əvvəlcə 3 addımı tamamlayın: logistika, komissiya, RUB konvertasiyası.',
            ! $invoice->salesDocuments()->where('kind', 'proforma')->exists() => 'Alıcı üçün proforma faktura yoxdur.',
            ! $this->flow($company) => 'Təsdiq axını qurulmayıb: Tənzimləmələr → Təsdiq axını.',
            default => null,
        };
    }

    public function submit(Invoice $invoice, User $by): void
    {
        $company = tenant();
        if ($why = $this->blocker($invoice, $company)) {
            throw ValidationException::withMessages(['approval' => $why]);
        }
        DB::transaction(function () use ($invoice, $by, $company) {
            $invoice->update(['approval_status' => 'pending', 'approval_step' => 0, 'approval_flow' => $this->flow($company),
                'submitted_by' => $by->id, 'submitted_at' => now(), 'approved_at' => null]);
            $this->log($invoice, 'submitted', $by, null);
        });
        $this->notifyApprover($invoice->fresh());
    }

    public function decide(Invoice $invoice, User $by, bool $approve, ?string $comment): void
    {
        if ($invoice->approval_status !== 'pending' || $invoice->currentApproverId() !== $by->id) {
            throw ValidationException::withMessages(['approval' => 'Bu faktura hazırda sizin təsdiqinizi gözləmir.']);
        }
        if (! $approve && trim((string) $comment) === '') {
            throw ValidationException::withMessages(['comment' => 'Geri qaytarmağın səbəbini yazın.']);
        }

        $final = false;
        DB::transaction(function () use ($invoice, $by, $approve, $comment, &$final) {
            $this->log($invoice, $approve ? 'approved' : 'rejected', $by, $comment);
            $this->markRead($invoice, $by);
            if (! $approve) {
                $invoice->update(['approval_status' => 'rejected', 'approval_step' => null]);

                return;
            }
            $next = $invoice->approval_step + 1;
            if ($next < count($invoice->approval_flow ?? [])) {
                $invoice->update(['approval_step' => $next]);

                return;
            }
            $invoice->update(['approval_status' => 'approved', 'approval_step' => null, 'approved_at' => now(), 'status' => 'confirmed']);
            $this->documents->issueCommercial($invoice->fresh());
            $final = true;
        });

        $invoice->refresh();
        if ($invoice->approval_status === 'pending') {
            $this->notifyApprover($invoice);
        } else {
            $this->notifySubmitter($invoice, $by, $final, $comment);
        }
    }

    /** The submitter (or an admin) takes a pending request back; the invoice unlocks. */
    public function withdraw(Invoice $invoice, User $by): void
    {
        if ($invoice->approval_status !== 'pending') {
            throw ValidationException::withMessages(['approval' => 'Təsdiqdə olmayan faktura geri çəkilə bilməz.']);
        }
        if ($invoice->submitted_by !== $by->id && ! $by->role?->is_admin) {
            throw ValidationException::withMessages(['approval' => 'Yalnız göndərən şəxs və ya admin geri çəkə bilər.']);
        }
        DB::transaction(function () use ($invoice, $by) {
            Reminder::where('source', 'approval')->where('remindable_type', 'invoice')->where('remindable_id', $invoice->id)->whereNull('read_at')->update(['read_at' => now()]);
            $invoice->update(['approval_status' => null, 'approval_step' => null]);
            $this->log($invoice, 'withdrawn', $by, null);
        });
    }

    private function log(Invoice $invoice, string $action, User $by, ?string $comment): void
    {
        InvoiceApproval::create(['invoice_id' => $invoice->id, 'step' => $invoice->approval_step, 'user_id' => $by->id, 'action' => $action, 'comment' => $comment ?: null]);
    }

    private function notifyApprover(Invoice $invoice): void
    {
        $userId = $invoice->currentApproverId();
        if (! $userId) {
            return;
        }
        $step = $invoice->approval_flow[$invoice->approval_step];
        $this->remind($invoice, $userId, 'Təsdiq gözləyir: faktura '.$invoice->number,
            trim(($step['title'] ? $step['title'].' · ' : '').'Tədarük '.$invoice->deal?->code.' · '.($invoice->approval_step + 1).'/'.count($invoice->approval_flow).' addım'),
            'approval:'.$invoice->id.':'.$invoice->submitted_at?->timestamp.':'.$invoice->approval_step);
    }

    private function notifySubmitter(Invoice $invoice, User $by, bool $approved, ?string $comment): void
    {
        if (! $invoice->submitted_by) {
            return;
        }
        $this->remind($invoice, $invoice->submitted_by,
            $approved ? 'Faktura '.$invoice->number.' təsdiqləndi' : 'Faktura '.$invoice->number.' geri qaytarıldı',
            $approved ? 'Kommersiya fakturası hazırdır.' : $by->name.': '.$comment,
            'approval-result:'.$invoice->id.':'.now()->timestamp);
    }

    private function remind(Invoice $invoice, int $userId, string $title, string $body, string $key): void
    {
        Reminder::firstOrCreate(['dedupe_key' => mb_substr($key, 0, 190)], [
            'user_id' => $userId, 'source' => 'approval', 'title' => mb_substr($title, 0, 255), 'body' => $body,
            'url' => route('invoices.show', $invoice, false), 'remindable_type' => 'invoice', 'remindable_id' => $invoice->id, 'remind_at' => now(),
        ]);
    }

    private function markRead(Invoice $invoice, User $by): void
    {
        Reminder::where('source', 'approval')->where('user_id', $by->id)->where('remindable_type', 'invoice')->where('remindable_id', $invoice->id)
            ->whereNull('read_at')->update(['read_at' => now()]);
    }
}
