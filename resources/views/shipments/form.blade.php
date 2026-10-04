@php $editing = $shipment->exists; @endphp
<x-layouts.app :title="$editing ? 'Yük '.$shipment->number : 'Yeni yük'">
    <x-page-header :title="$editing ? 'Yük '.$shipment->number : 'Yeni yük'" :back="$editing ? route('shipments.show', $shipment) : route('shipments.index')"/>

    <form method="POST" action="{{ $editing ? route('shipments.update', $shipment) : route('shipments.store') }}" class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        @if($editing) @method('PUT') @endif
        <div class="space-y-6 min-w-0">
            <section class="card p-6">
                <h2 class="text-base font-semibold mb-5">{{ __('Marşrut') }}</h2>
                <div class="grid sm:grid-cols-4 gap-4">
                    <x-input name="number" :label="__('Nömrə')" :value="$shipment->number" required class="font-mono"/>
                    <x-select name="direction" :label="__('İstiqamət')" :options="config('glaust.shipment_directions')" :value="$shipment->direction" required/>
                    <x-select name="transport_mode" :label="__('Nəqliyyat növü')" :options="config('glaust.transport_modes')" :value="$shipment->transport_mode" required wrapper="sm:col-span-2"/>
                    <x-input name="origin" :label="__('Çıxış məntəqəsi')" :value="$shipment->origin" required wrapper="sm:col-span-2" :placeholder="__('Məs: İstanbul, Türkiyə')"/>
                    <x-input name="destination" :label="__('Təyinat məntəqəsi')" :value="$shipment->destination" required wrapper="sm:col-span-2" :placeholder="__('Məs: Bakı, Azərbaycan')"/>
                    <x-input name="loading_date" type="date" :label="__('Yüklənmə tarixi')" :value="$shipment->loading_date" wrapper="sm:col-span-2"/>
                    <x-input name="eta" type="date" :label="__('Gözlənilən çatma')" :value="$shipment->eta" wrapper="sm:col-span-2" :hint="__('Keçəndə məsul şəxsə xatırlatma gedir.')"/>
                </div>
            </section>
            <section class="card p-6">
                <h2 class="text-base font-semibold mb-5">{{ __('Daşıma və yük') }}</h2>
                <div class="grid sm:grid-cols-4 gap-4">
                    <x-combobox name="carrier_id" :label="__('Daşıyıcı')" :url="route('ajax.lookup', ['counterparties', 'role' => 'supplier'])" :value="$shipment->carrier_id" :display="$shipment->carrier?->name" :placeholder="__('CRM-dən təchizatçı')" wrapper="sm:col-span-2"/>
                    <x-input name="vehicle" :label="__('Nəqliyyat vasitəsi')" :value="$shipment->vehicle" wrapper="sm:col-span-2" :placeholder="__('Dövlət nömrəsi / reys / gəmi')"/>
                    <x-input name="container_no" :label="__('Konteyner №')" :value="$shipment->container_no" wrapper="sm:col-span-2" class="font-mono uppercase"/>
                    <x-input name="document_no" :label="__('CMR / konosament / AWB')" :value="$shipment->document_no" wrapper="sm:col-span-2" class="font-mono"/>
                    <x-input name="cargo_description" :label="__('Yükün təsviri')" :value="$shipment->cargo_description" wrapper="sm:col-span-4"/>
                    <x-input name="weight_kg" :label="__('Çəki (kq)')" :value="$shipment->weight_kg" wrapper="sm:col-span-2" inputmode="decimal" class="font-mono text-right"/>
                    <x-input name="volume_m3" :label="__('Həcm (m³)')" :value="$shipment->volume_m3" wrapper="sm:col-span-2" inputmode="decimal" class="font-mono text-right"/>
                </div>
            </section>
        </div>
        <aside class="space-y-6 lg:sticky lg:top-24">
            <section class="card p-6 space-y-4">
                <x-select name="status" :label="__('Status')" :options="status_options('shipment')" :value="$shipment->status" required/>
                <x-select name="responsible_id" :label="__('Məsul şəxs')" :options="\App\Http\Controllers\ShipmentController::users()" :value="$shipment->responsible_id" placeholder="—"/>
                <x-combobox name="project_id" :label="__('Layihə')" :url="route('ajax.lookup', 'projects')" :value="$shipment->project_id" :display="$shipment->project?->name"/>
                <x-combobox name="contract_id" :label="__('Müqavilə')" :url="route('ajax.lookup', 'contracts')" :value="$shipment->contract_id" :display="$shipment->contract?->number"/>
                <x-input name="notes" type="textarea" :label="__('Qeydlər')" :value="$shipment->notes" rows="3"/>
            </section>
            <div class="flex gap-2">
                <a href="{{ $editing ? route('shipments.show', $shipment) : route('shipments.index') }}" class="btn btn-secondary flex-1">{{ __('Ləğv et') }}</a>
                <button class="btn btn-primary flex-1" :disabled="busy"><x-icon name="check" class="size-4"/> {{ $editing ? 'Yadda saxla' : 'Yarat' }}</button>
            </div>
        </aside>
    </form>
</x-layouts.app>
