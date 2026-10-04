@php $editing = $deal->exists; @endphp
<x-layouts.app :title="$editing ? 'Sövdələşmə '.$deal->code : 'Yeni sövdələşmə'">
    <x-page-header :title="$editing ? 'Sövdələşmə '.$deal->code : 'Yeni sövdələşmə'" :back="$editing ? route('deals.show', $deal) : route('projects.show', [$project, 'tab' => 'deals'])"
                   :subtitle="'Layihə: '.$project->code.' · '.$project->name.' — məhsulu satıcıdan alıb alıcıya satırıq'"/>

    @if($errors->any())
        <div class="card border-danger/30 bg-danger-soft/50 p-4 mb-6 text-sm text-danger flex gap-2" role="alert">
            <x-icon name="alert" class="size-5 shrink-0"/> Formda {{ $errors->count() }} xəta var — aşağıdakı sahələri yoxlayın.
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data" action="{{ $editing ? route('deals.update', $deal) : route('deals.store', $project) }}"
          class="grid xl:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        @if($editing) @method('PUT') @endif
        <div class="space-y-6 min-w-0">
            <section class="card p-6">
                <h2 class="text-base font-semibold mb-5">Sövdələşmə</h2>
                <div class="grid sm:grid-cols-4 gap-4">
                    <x-input name="code" label="Kod" :value="$deal->code" required class="font-mono" hint="Avtomatik"/>
                    <x-input name="title" label="Ad" :value="$deal->title" required wrapper="sm:col-span-3" placeholder="Məs: Boya partiyası — oktyabr"/>
                    <x-input name="deal_date" type="date" label="Tarix" :value="$deal->deal_date" required/>
                    <x-select name="currency" label="Valyuta" :options="array_combine(config('glaust.currencies'), config('glaust.currencies'))" :value="$deal->currency" required/>
                </div>
            </section>

            @include('partials.sides-form', ['holder' => $deal, 'files' => true])
        </div>

        <aside class="space-y-6 xl:sticky xl:top-24">
            <section class="card p-6 space-y-4">
                <x-select name="status" label="Status" :options="status_options('deal')" :value="$deal->status" required/>
                <x-select name="responsible_id" label="Məsul şəxs" :options="\App\Http\Controllers\DealController::users()" :value="$deal->responsible_id" placeholder="—"/>
                <x-input name="notes" type="textarea" label="Qeydlər" :value="$deal->notes" rows="4"/>
            </section>
            <div class="flex gap-2">
                <a href="{{ $editing ? route('deals.show', $deal) : route('projects.show', [$project, 'tab' => 'deals']) }}" class="btn btn-secondary flex-1">Ləğv et</a>
                <button class="btn btn-primary flex-1" :disabled="busy"><x-icon name="check" class="size-4"/> {{ $editing ? 'Yadda saxla' : 'Yarat' }}</button>
            </div>
            @unless($editing)
                <p class="text-xs text-muted px-1">Növbəti addım: sövdələşmə yaradıldıqdan sonra satıcının fakturasını (proforma) Excel şablonu ilə import edəcəksiniz.</p>
            @endunless
        </aside>
    </form>
</x-layouts.app>
