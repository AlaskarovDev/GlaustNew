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

            @include('partials.sides-form', ['holder' => $project, 'files' => false])
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
