<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files live on the private "local" disk (storage/app/private), never under the web
 * root, and are streamed only after the parent record's permission is checked.
 */
class AttachmentController extends Controller
{
    /** morph alias => [view permission, edit permission] */
    private const ABILITIES = [
        'counterparty' => ['crm.view', 'crm.update'],
        'project' => ['projects.view', 'projects.update'],
        'task' => ['projects.view', 'projects.view'],
        'contract' => ['contracts.view', 'contracts.update'],
        'bank_transaction' => ['bank.view', 'bank.update'],
        'shipment' => ['logistics.view', 'logistics.update'],
        'deal' => ['projects.view', 'projects.update'],
        'invoice' => ['projects.view', 'projects.update'],
    ];

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'attachable_type' => ['required', 'in:'.implode(',', array_keys(self::ABILITIES))],
            'attachable_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:'.config('glaust.upload.max_kb'), 'mimes:'.config('glaust.upload.mimes')],
        ], [], ['file' => __('Fayl')]);

        $this->authorize(self::ABILITIES[$data['attachable_type']][1]);

        // Resolving through the model applies the tenant scope: another company's id is a 404.
        $class = Relation::getMorphedModel($data['attachable_type']);
        $parent = $class::findOrFail($data['attachable_id']);

        $this->checkQuota($request->file('file')->getSize());

        $file = $request->file('file');
        $path = $file->storeAs('attachments/'.tenant()->id.'/'.now()->format('Y/m'), Str::uuid().'.'.$file->extension(), 'local');

        $parent->attachments()->create([
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 190),
            'path' => $path,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', __('Fayl yükləndi.'));
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        $this->authorize(self::ABILITIES[$attachment->attachable_type][0] ?? 'settings.view');
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }

    public function destroy(Attachment $attachment): RedirectResponse
    {
        $this->authorize(self::ABILITIES[$attachment->attachable_type][1] ?? 'settings.update');
        $attachment->delete();

        return back()->with('success', __('Fayl silindi.'));
    }

    private function checkQuota(int $incoming): void
    {
        $limitMb = tenant()->plan?->max_storage_mb;
        if (! $limitMb) {
            return;
        }
        $used = (int) Attachment::sum('size');
        if ($used + $incoming > $limitMb * 1024 * 1024) {
            abort(back()->with('error', __('Tarif üzrə yaddaş limiti (').$limitMb.__(' MB) dolub.')));
        }
    }
}
