@php
    $editing = $project->exists;
    $team = \App\Http\Controllers\ProjectController::teamOptions();
    $selected = array_map('intval', old('members', $memberIds));
@endphp
<x-layouts.app :title="$editing ? $project->name : 'Yeni layihə'">
    <x-page-header :title="$editing ? $project->name : 'Yeni layihə'" :back="$editing ? route('projects.show', $project) : route('projects.index')"/>

    <form method="POST" action="{{ $editing ? route('projects.update', $project) : route('projects.store') }}" class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        @if($editing) @method('PUT') @endif
        <div class="space-y-6 min-w-0">
            <section class="card p-6">
                <h2 class="text-base font-semibold mb-5">Layihə</h2>
                <div class="grid sm:grid-cols-4 gap-4">
                    <x-input name="code" label="Kod" :value="$project->code" required class="font-mono" hint="Avtomatik"/>
                    <x-input name="name" label="Layihənin adı" :value="$project->name" required wrapper="sm:col-span-3"/>
                    <x-input name="description" type="textarea" label="Təsvir" :value="$project->description" rows="4" wrapper="sm:col-span-4"/>
                </div>
            </section>

            {{-- The two sides of the deal, each with its own contract --}}
            <div class="grid xl:grid-cols-2 gap-6">
                @foreach([
                    ['party' => 'counterparty_id', 'contract' => 'sale_contract_id', 'kind' => 'sale', 'role' => 'customer', 'icon' => 'arrow-up-right', 'tone' => 'bg-brand-soft text-brand', 'bar' => 'before:bg-brand',
                     'title' => 'Məhsulu alan tərəf', 'sub' => 'Müştəri və onunla satış müqaviləsi', 'partyLabel' => 'Alıcı (müştəri)', 'contractLabel' => 'Satış müqaviləsi',
                     'partyValue' => $project->counterparty, 'contractValue' => $project->saleContract],
                    ['party' => 'supplier_id', 'contract' => 'purchase_contract_id', 'kind' => 'purchase', 'role' => 'supplier', 'icon' => 'arrow-down-left', 'tone' => 'bg-saffron-soft text-saffron', 'bar' => 'before:bg-saffron',
                     'title' => 'Məhsulu göndərən tərəf', 'sub' => 'Təchizatçı və onunla alış müqaviləsi', 'partyLabel' => 'Göndərən (təchizatçı)', 'contractLabel' => 'Alış müqaviləsi',
                     'partyValue' => $project->supplier, 'contractValue' => $project->purchaseContract],
                ] as $side)
                    <section id="{{ $side['kind'] }}" class="scroll-mt-24 card p-6 relative overflow-hidden before:absolute before:inset-x-0 before:top-0 before:h-1 {{ $side['bar'] }}">
                        <div class="flex items-center gap-3 mb-5">
                            <span class="grid place-items-center size-10 rounded-xl {{ $side['tone'] }}"><x-icon :name="$side['icon']" class="size-5"/></span>
                            <div><h2 class="text-base font-semibold">{{ $side['title'] }}</h2><p class="text-xs text-muted">{{ $side['sub'] }}</p></div>
                        </div>
                        <div class="space-y-4">
                            <x-combobox :name="$side['party']" :label="$side['partyLabel']" :url="route('ajax.lookup', ['counterparties', 'role' => $side['role']])"
                                        :value="$side['partyValue']?->id" :display="$side['partyValue']?->name" placeholder="CRM-dən seçin"/>
                            <x-combobox :name="$side['contract']" :label="$side['contractLabel']" :url="route('ajax.lookup', ['contracts', 'kind' => $side['kind']])"
                                        :depends="$side['party']" :party-id="$side['contractValue']?->counterparty_id"
                                        :value="$side['contractValue']?->id" :display="$side['contractValue'] ? $side['contractValue']->number.' · '.$side['contractValue']->subject : null"
                                        placeholder="Mövcud müqaviləni seçin"
                                        hint="Siyahıda yalnız seçilmiş tərəfin {{ $side['kind'] === 'sale' ? 'satış' : 'alış' }} müqavilələri görünür. Müqavilə seçsəniz, tərəf avtomatik dolur."/>
                        </div>
                    </section>
                @endforeach
            </div>
            <section class="card p-6">
                <h2 class="text-base font-semibold mb-5">Müddət və büdcə</h2>
                <div class="grid sm:grid-cols-4 gap-4">
                    <x-input name="start_date" type="date" label="Başlama" :value="$project->start_date"/>
                    <x-input name="end_date" type="date" label="Bitmə" :value="$project->end_date"/>
                    <x-input name="budget" label="Büdcə" :value="$project->budget" inputmode="decimal" class="font-mono text-right"/>
                    <x-select name="currency" label="Valyuta" :options="array_combine(config('glaust.currencies'), config('glaust.currencies'))" :value="$project->currency" required/>
                </div>
            </section>
            <section class="card p-6">
                <h2 class="text-base font-semibold mb-1">Komanda</h2>
                <p class="text-xs text-muted mb-4">Menecer avtomatik komandaya daxil edilir.</p>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                    @foreach($team as $id => $name)
                        <label class="flex items-center gap-2.5 p-2.5 rounded-lg border border-line hover:border-line-strong cursor-pointer has-[:checked]:border-brand has-[:checked]:bg-brand-soft/50 transition-colors">
                            <input type="checkbox" name="members[]" value="{{ $id }}" class="checkbox" @checked(in_array($id, $selected, true))>
                            <span class="text-sm truncate">{{ $name }}</span>
                        </label>
                    @endforeach
                </div>
            </section>
        </div>
        <aside class="space-y-6 lg:sticky lg:top-24">
            <section class="card p-6 space-y-4">
                <x-select name="status" label="Status" :options="status_options('project')" :value="$project->status" required/>
                <x-select name="priority" label="Prioritet" :options="status_options('priority')" :value="$project->priority" required/>
                <x-select name="manager_id" label="Menecer" :options="$team" :value="$project->manager_id" placeholder="— seçilməyib —"/>
            </section>
            <div class="flex gap-2">
                <a href="{{ $editing ? route('projects.show', $project) : route('projects.index') }}" class="btn btn-secondary flex-1">Ləğv et</a>
                <button class="btn btn-primary flex-1" :disabled="busy"><x-icon name="check" class="size-4"/> {{ $editing ? 'Yadda saxla' : 'Yarat' }}</button>
            </div>
        </aside>
    </form>
</x-layouts.app>
