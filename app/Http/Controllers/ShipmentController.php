<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Shipment;
use App\Models\ShipmentCost;
use App\Models\User;
use App\Rules\TenantExists;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use App\Services\NumberGenerator;
use App\Tables\ShipmentTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ShipmentController extends Controller
{
    public function __construct(private NumberGenerator $numbers) {}

    public function index(Request $request): View
    {
        $table = new ShipmentTable($request);
        $counts = Shipment::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $delayed = Shipment::whereNotIn('status', ['arrived', 'delivered'])->where('eta', '<', today()->toDateString())->count();

        return view('shipments.index', ['table' => $table, 'items' => $table->paginate(), 'counts' => $counts, 'delayed' => $delayed]);
    }

    public function export(Request $request): Response
    {
        return (new ShipmentTable($request))->export((string) $request->query('format', 'xlsx'));
    }

    public function create(Request $request): View
    {
        $this->authorize('logistics.create');

        $shipment = new Shipment([
            'number' => $this->numbers->next('shipment'), 'direction' => 'import', 'transport_mode' => 'road',
            'status' => 'planned', 'loading_date' => today(), 'responsible_id' => $request->user()->id,
            'project_id' => $request->integer('project_id') ?: null, 'contract_id' => $request->integer('contract_id') ?: null,
        ]);

        return view('shipments.form', ['shipment' => $shipment->load('project', 'contract')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('logistics.create');
        $shipment = DB::transaction(function () use ($request) {
            $shipment = Shipment::create($this->validated($request));
            $shipment->history()->create(['status' => $shipment->status, 'changed_by' => $request->user()->id, 'note' => __('Yük yaradıldı')]);

            return $shipment;
        });

        return redirect()->route('shipments.show', $shipment)->with('success', __('Yük :v1 yaradıldı.', ['v1' => $shipment->number]));
    }

    public function show(Shipment $shipment): View
    {
        $shipment->load(['carrier', 'project', 'contract', 'responsible', 'history.user', 'costs.counterparty', 'attachments.uploader']);
        $history = AuditLog::with('user')->where('auditable_type', 'shipment')->where('auditable_id', $shipment->id)->latest('created_at')->limit(15)->get();

        return view('shipments.show', compact('shipment', 'history'));
    }

    public function edit(Shipment $shipment): View
    {
        $this->authorize('logistics.update');

        return view('shipments.form', ['shipment' => $shipment->load('carrier', 'project', 'contract')]);
    }

    public function update(Request $request, Shipment $shipment): RedirectResponse
    {
        $this->authorize('logistics.update');
        DB::transaction(function () use ($request, $shipment) {
            $old = $shipment->status;
            $shipment->update($this->validated($request, $shipment));
            if ($old !== $shipment->status) {
                $this->afterStatusChange($shipment, $request->user()->id, null);
            }
        });

        return redirect()->route('shipments.show', $shipment)->with('success', __('Yük yeniləndi.'));
    }

    public function destroy(Shipment $shipment): RedirectResponse
    {
        $this->authorize('logistics.delete');
        $shipment->delete();

        return redirect()->route('shipments.index')->with('success', __('Yük :v1 silindi.', ['v1' => $shipment->number]));
    }

    public function status(Request $request, Shipment $shipment): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Shipment::FLOW)],
            'note' => ['nullable', 'string', 'max:190'],
        ]);
        if ($data['status'] === $shipment->status) {
            return back();
        }
        DB::transaction(function () use ($shipment, $data, $request) {
            $shipment->update(['status' => $data['status']]);
            $this->afterStatusChange($shipment, $request->user()->id, $data['note'] ?? null);
        });

        return back()->with('success', 'Status: '.status_label('shipment', $data['status']));
    }

    public function storeCost(Request $request, Shipment $shipment, CurrencyRates $rates): RedirectResponse
    {
        $request->merge(['amount' => parse_number($request->input('amount'))]);
        $data = $request->validate([
            'cost_type' => ['required', Rule::in(array_keys(config('glaust.cost_types')))],
            'counterparty_id' => ['nullable', 'integer', TenantExists::in('counterparties')],
            'cost_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'currency' => ['required', Rule::in(config('glaust.currencies'))],
            'note' => ['nullable', 'string', 'max:190'],
        ], [], ['cost_type' => __('Xərc növü'), 'cost_date' => __('Tarix')]);

        try {
            $rate = $rates->rate($data['currency'], $data['cost_date']);
        } catch (RateUnavailable $e) {
            throw ValidationException::withMessages(['currency' => $e->getMessage()]);
        }

        $shipment->costs()->create($data + ['cbar_rate' => $rate, 'amount_azn' => round($data['amount'] * $rate, 2)]);

        return back()->with('success', __('Xərc əlavə edildi.'));
    }

    public function destroyCost(Shipment $shipment, ShipmentCost $cost): RedirectResponse
    {
        abort_unless($cost->shipment_id === $shipment->id, 404);
        $cost->delete();

        return back()->with('success', __('Xərc silindi.'));
    }

    private function afterStatusChange(Shipment $shipment, int $userId, ?string $note): void
    {
        $shipment->history()->create(['status' => $shipment->status, 'changed_by' => $userId, 'note' => $note]);
        if ($shipment->status === 'delivered' && ! $shipment->delivered_at) {
            $shipment->forceFill(['delivered_at' => now()])->save();
        }
        if ($shipment->status !== 'delivered' && $shipment->delivered_at) {
            $shipment->forceFill(['delivered_at' => null])->save();
        }
    }

    private function validated(Request $request, ?Shipment $shipment = null): array
    {
        if (blank($request->input('number')) && ! $shipment) {
            $request->merge(['number' => $this->numbers->next('shipment')]);
        }
        $request->merge(['weight_kg' => parse_number($request->input('weight_kg')), 'volume_m3' => parse_number($request->input('volume_m3'))]);

        $data = $request->validate([
            'number' => ['required', 'string', 'max:40', Rule::unique('shipments', 'number')->where('company_id', tenant()->id)->ignore($shipment?->id)],
            'direction' => ['required', Rule::in(array_keys(config('glaust.shipment_directions')))],
            'origin' => ['required', 'string', 'max:190'],
            'destination' => ['required', 'string', 'max:190'],
            'carrier_id' => ['nullable', 'integer', TenantExists::in('counterparties')],
            'transport_mode' => ['required', Rule::in(array_keys(config('glaust.transport_modes')))],
            'vehicle' => ['nullable', 'string', 'max:190'],
            'container_no' => ['nullable', 'string', 'max:40'],
            'document_no' => ['nullable', 'string', 'max:60'],
            'cargo_description' => ['nullable', 'string', 'max:255'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'],
            'loading_date' => ['nullable', 'date'],
            'eta' => ['nullable', 'date', 'after_or_equal:loading_date'],
            'status' => ['required', Rule::in(Shipment::FLOW)],
            'project_id' => ['nullable', 'integer', TenantExists::in('projects')],
            'contract_id' => ['nullable', 'integer', TenantExists::in('contracts')],
            'responsible_id' => ['nullable', 'integer', TenantExists::plain('users')],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['number.unique' => __('Bu nömrə ilə yük artıq var.')], [
            'eta' => __('Gözlənilən çatma tarixi'), 'loading_date' => __('Yüklənmə tarixi'), 'carrier_id' => __('Daşıyıcı'), 'weight_kg' => __('Çəki'), 'volume_m3' => __('Həcm'),
        ]);

        if (! empty($data['carrier_id']) && ! \App\Models\Counterparty::find($data['carrier_id'])?->isSupplier()) {
            throw ValidationException::withMessages(['carrier_id' => __('Daşıyıcı CRM-də təchizatçı kimi qeyd olunmalıdır.')]);
        }

        return $data;
    }

    public static function users(): array
    {
        return User::forTenant()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
