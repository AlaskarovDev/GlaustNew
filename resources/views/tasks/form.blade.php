@php
    $editing = $task->exists;
    $users = \App\Http\Controllers\TaskController::users();
    $milestones = $task->project_id ? \App\Models\Milestone::where('project_id', $task->project_id)->orderBy('due_date')->pluck('name', 'id')->all() : [];
@endphp
<x-layouts.app :title="$editing ? $task->title : __('Yeni tapşırıq')">
    <x-page-header :title="$editing ? __('Tapşırığı redaktə et') : __('Yeni tapşırıq')" :back="$editing ? route('tasks.show', $task) : ($task->project_id ? route('projects.show', [$task->project_id, 'tab' => 'board']) : route('tasks.index'))"/>

    <form method="POST" action="{{ $editing ? route('tasks.update', $task) : route('tasks.store') }}" class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        @if($editing) @method('PUT') @endif
        @if(! $editing && $task->project_id)<input type="hidden" name="redirect" value="project">@endif
        <section class="card p-6 space-y-4 min-w-0">
            <x-input name="title" :label="__('Başlıq')" :value="$task->title" required autofocus :placeholder="__('Nə edilməlidir?')"/>
            <x-input name="description" type="textarea" :label="__('Təsvir')" :value="$task->description" rows="8"/>
        </section>
        <aside class="space-y-6 lg:sticky lg:top-24">
            <section class="card p-6 space-y-4">
                <x-combobox name="project_id" :label="__('Layihə')" :url="route('ajax.lookup', 'projects')" :value="$task->project_id" :display="$task->project?->name" :placeholder="__('Layihəsiz')"/>
                @if($milestones)
                    <x-select name="milestone_id" :label="__('Mərhələ')" :options="$milestones" :value="$task->milestone_id" placeholder="—"/>
                @endif
                <x-select name="assignee_id" :label="__('Məsul şəxs')" :options="$users" :value="$task->assignee_id" :placeholder="__('— təyin edilməyib —')"/>
                <div class="grid grid-cols-2 gap-3">
                    <x-select name="status" :label="__('Status')" :options="status_options('task')" :value="$task->status" required/>
                    <x-select name="priority" :label="__('Prioritet')" :options="status_options('priority')" :value="$task->priority" required/>
                    <x-input name="start_date" type="date" :label="__('Başlama')" :value="$task->start_date"/>
                    <x-input name="due_date" type="date" :label="__('Son tarix')" :value="$task->due_date"/>
                </div>
                <x-input name="estimated_hours" :label="__('Plan (saat)')" :value="$task->estimated_hours" inputmode="decimal" class="font-mono"/>
            </section>
            <div class="flex gap-2">
                <a href="{{ url()->previous() }}" class="btn btn-secondary flex-1">{{ __('Ləğv et') }}</a>
                <button class="btn btn-primary flex-1" :disabled="busy"><x-icon name="check" class="size-4"/> {{ $editing ? 'Yadda saxla' : 'Yarat' }}</button>
            </div>
        </aside>
    </form>
</x-layouts.app>
