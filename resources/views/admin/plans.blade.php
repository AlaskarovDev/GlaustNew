<x-layouts.admin title="Tariflər">
    <x-page-header title="Tariflər" subtitle="İstifadəçi limiti, yaddaş və modullar"/>
    @php $moduleLabels = collect(config('glaust.plan_modules'))->mapWithKeys(fn ($m) => [$m => config("glaust.modules.$m.label")]); @endphp
    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-5">
        @foreach($plans->push(new \App\Models\Plan(['is_active' => true, 'modules' => config('glaust.plan_modules'), 'max_users' => 5, 'max_storage_mb' => 1024, 'monthly_price' => 0])) as $plan)
            <form method="POST" action="{{ $plan->exists ? route('admin.plans.update', $plan) : route('admin.plans.store') }}" @class(['card p-5 space-y-3', 'border-dashed' => ! $plan->exists])>
                @csrf @if($plan->exists) @method('PUT') @endif
                <div class="flex items-center justify-between"><h2 class="font-semibold">{{ $plan->exists ? $plan->name : 'Yeni tarif' }}</h2>@if($plan->exists)<span class="text-xs text-muted">{{ $plan->companies_count }} şirkət</span>@endif</div>
                <div class="grid grid-cols-2 gap-3">
                    <x-input name="name" label="Ad" :value="$plan->name" required/>
                    <x-input name="code" label="Kod" :value="$plan->code" required class="font-mono"/>
                    <x-input name="max_users" type="number" label="İstifadəçi" :value="$plan->max_users" required/>
                    <x-input name="max_storage_mb" type="number" label="Yaddaş (MB)" :value="$plan->max_storage_mb" required/>
                    <x-input name="monthly_price" label="Aylıq qiymət (AZN)" :value="$plan->monthly_price" required wrapper="col-span-2"/>
                </div>
                <fieldset><legend class="field-label">Modullar</legend>
                    <div class="grid grid-cols-2 gap-1.5">
                        @foreach($moduleLabels as $m => $l)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="modules[]" value="{{ $m }}" class="checkbox" @checked(in_array($m, $plan->modules ?? [], true))> {{ $l }}</label>@endforeach
                    </div>
                </fieldset>
                <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="checkbox" @checked($plan->is_active)> Aktiv</label>
                <button class="btn {{ $plan->exists ? 'btn-secondary' : 'btn-primary' }} w-full">{{ $plan->exists ? 'Yadda saxla' : 'Yarat' }}</button>
            </form>
        @endforeach
    </div>
</x-layouts.admin>
