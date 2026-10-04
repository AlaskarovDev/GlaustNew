<x-layouts.admin title="Yeni şirkət">
    <x-page-header title="Yeni şirkət" :back="route('admin.companies.index')" subtitle="Şirkət yaradılır; istifadəçiləri sonra şirkət səhifəsində əlavə edirsiniz"/>
    <form method="POST" action="{{ route('admin.companies.store') }}" class="card p-6 space-y-4 max-w-2xl">
        @csrf
        <x-input name="name" label="Şirkətin adı" required/>
        <div class="grid sm:grid-cols-2 gap-4">
            <x-input name="voen" label="VÖEN"/>
            <x-input name="phone" label="Telefon"/>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <x-input name="email" type="email" label="Email"/>
            <x-input name="address" label="Ünvan"/>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <x-select name="plan_id" label="Tarif" :options="$plans->pluck('name', 'id')->all()" placeholder="Avtomatik"/>
            <x-select name="subscription_status" label="Status" :options="['active' => 'Aktiv', 'trial' => 'Sınaq']" value="active" required/>
        </div>
        <button class="btn btn-primary"><x-icon name="check" class="size-4"/> Şirkəti yarat</button>
    </form>
</x-layouts.admin>
