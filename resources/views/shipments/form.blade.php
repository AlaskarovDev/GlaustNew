@php $editing = $shipment->exists; @endphp
<x-layouts.app :title="$editing ? 'Yük '.$shipment->number : 'Yeni yük'">
    <x-page-header :title="$editing ? 'Yük '.$shipment->number : 'Yeni yük'" :back="$editing ? route('shipments.show', $shipment) : route('shipments.index')"/>

    <form method="POST" action="{{ $editing ? route('shipments.update', $shipment) : route('shipments.store') }}" class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        @if($editing) @method('PUT') @endif
        <div class="space-y-6 min-w-0">
            <section class="card p-6">
                <h2 class="text-base font-semibold mb-5">Marşrut</h2>
                <div class="grid sm:grid-cols-4 gap-4">
                    <x-input name="number" label="Nömrə" :value="$shipment->number" required class="font-mono"/>
                    <x-select name="direction" label="İstiqamət" :options="config('glaust.shipment_directions')" :value="$shipment->direction" required/>
                    <x-select name="transport_mode" label="Nəqliyyat növü" :options="config('glaust.transport_modes')" :value="$shipment->transport_mode" required wrapper="sm:col-span-2"/>
                    <x-input name="origin" label="Çıxış məntəqəsi" :value="$shipment->origin" required wrapper="sm:col-span-2" placeholder="Məs: İstanbul, Türkiyə"/>
                    <x-input name="destination" label="Təyinat məntəqəsi" :value="$shipment->destination" required wrapper="sm:col-span-2" placeholder="Məs: Bakı, Azərbaycan"/>
                    <x-input name="loading_date" type="date" label="Yüklənmə tarixi" :value="$shipment->loading_date" wrapper="sm:col-span-2"/>
                    <x-input name="eta" type="date" label="Gözlənilən çatma" :value="$shipment->eta" wrapper="sm:col-span-2" hint="Keçəndə məsul şəxsə xatırlatma gedir."/>
                </div>
            </section>
            <section class="card p-6">
                <h2 class="text-base font-semibold mb-5">Daşıma və yük</h2>
                <div class="grid sm:grid-cols-4 gap-4">
                    <x-combobox name="carrier_id" label="Daşıyıcı" :url="route('ajax.lookup', ['counterparties', 'role' => 'supplier'])" :value="$shipment->carrier_id" :display="$shipment->carrier?->name" placeholder="CRM-dən təchizatçı" wrapper="sm:col-span-2"/>
                    <x-input name="vehicle" label="Nəqliyyat vasitəsi" :value="$shipment->vehicle" wrapper="sm:col-span-2" placeholder="Dövlət nömrəsi / reys / gəmi"/>
                    <x-input name="container_no" label="Konteyner №" :value="$shipment->container_no" wrapper="sm:col-span-2" class="font-mono uppercase"/>
                    <x-input name="document_no" label="CMR / konosament / AWB" :value="$shipment->document_no" wrapper="sm:col-span-2" class="font-mono"/>
                    <x-input name="cargo_description" label="Yükün təsviri" :value="$shipment->cargo_description" wrapper="sm:col-span-4"/>
                    <x-input name="weight_kg" label="Çəki (kq)" :value="$shipment->weight_kg" wrapper="sm:col-span-2" inputmode="decimal" class="font-mono text-right"/>
                    <x-input name="volume_m3" label="Həcm (m³)" :value="$shipment->volume_m3" wrapper="sm:col-span-2" inputmode="decimal" class="font-mono text-right"/>
                </div>
            </section>
        </div>
        <aside class="space-y-6 lg:sticky lg:top-24">
            <section class="card p-6 space-y-4">
                <x-select name="status" label="Status" :options="status_options('shipment')" :value="$shipment->status" required/>
                <x-select name="responsible_id" label="Məsul şəxs" :options="\App\Http\Controllers\ShipmentController::users()" :value="$shipment->responsible_id" placeholder="—"/>
                <x-combobox name="project_id" label="Layihə" :url="route('ajax.lookup', 'projects')" :value="$shipment->project_id" :display="$shipment->project?->name"/>
                <x-combobox name="contract_id" label="Müqavilə" :url="route('ajax.lookup', 'contracts')" :value="$shipment->contract_id" :display="$shipment->contract?->number"/>
                <x-input name="notes" type="textarea" label="Qeydlər" :value="$shipment->notes" rows="3"/>
            </section>
            <div class="flex gap-2">
                <a href="{{ $editing ? route('shipments.show', $shipment) : route('shipments.index') }}" class="btn btn-secondary flex-1">Ləğv et</a>
                <button class="btn btn-primary flex-1" :disabled="busy"><x-icon name="check" class="size-4"/> {{ $editing ? 'Yadda saxla' : 'Yarat' }}</button>
            </div>
        </aside>
    </form>
</x-layouts.app>
