<x-layouts.app title="Şirkət">
    <x-page-header title="Tənzimləmələr" icon="settings"/>
    @include('settings._nav')
    <form method="POST" action="{{ route('settings.company.update') }}" enctype="multipart/form-data" class="card p-6 max-w-3xl space-y-5">
        @csrf @method('PUT')
        <h2 class="text-base font-semibold">Şirkət məlumatları</h2>
        <p class="text-sm text-muted -mt-3">PDF-lərdə (müqavilə, hesabat) və maillərdə istifadə olunur.</p>
        <div class="grid sm:grid-cols-2 gap-4">
            <x-input name="name" label="Şirkətin adı" :value="$company->name" required wrapper="sm:col-span-2"/>
            <x-input name="voen" label="VÖEN" :value="$company->voen" class="font-mono" maxlength="10"/>
            <x-input name="phone" label="Telefon" :value="$company->phone"/>
            <x-input name="email" type="email" label="Email" :value="$company->email"/>
            <x-input name="address" label="Ünvan" :value="$company->address"/>
            <x-input name="bank_details" label="Bank rekvizitləri" :value="$company->bank_details" wrapper="sm:col-span-2" placeholder="Bank, IBAN, SWIFT"/>
            <x-field label="Loqo" name="logo" class="sm:col-span-2" hint="PNG/JPG/WEBP, 1 MB-a qədər">
                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="input !h-auto py-2 text-sm">
            </x-field>
        </div>
        <div class="flex justify-end"><button class="btn btn-primary" @cannot('settings.update') disabled @endcannot>Yadda saxla</button></div>
    </form>
</x-layouts.app>
