<x-layouts.admin :title="__('Yeni şirkət')">
    <x-page-header :title="__('Yeni şirkət')" :back="route('admin.companies.index')" :subtitle="__('Şirkət yaradılır; istifadəçiləri sonra şirkət səhifəsində əlavə edirsiniz')"/>
    <form method="POST" action="{{ route('admin.companies.store') }}" class="card p-6 space-y-4 max-w-2xl">
        @csrf
        <x-input name="name" :label="__('Şirkətin adı')" required/>
        <div class="grid sm:grid-cols-2 gap-4">
            <x-input name="voen" :label="__('VÖEN')"/>
            <x-input name="phone" :label="__('Telefon')"/>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <x-input name="email" type="email" :label="__('Email')"/>
            <x-input name="address" :label="__('Ünvan')"/>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <x-select name="plan_id" :label="__('Tarif')" :options="$plans->pluck('name', 'id')->all()" :placeholder="__('Avtomatik')"/>
            <x-select name="subscription_status" :label="__('Status')" :options="['active' => __('Aktiv'), 'trial' => __('Sınaq')]" value="active" required/>
        </div>
        <button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Şirkəti yarat') }}</button>
    </form>
</x-layouts.admin>
