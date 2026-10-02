<x-layouts.app :title="$user->exists ? $user->name : 'İstifadəçi dəvəti'">
    <x-page-header :title="$user->exists ? $user->name : 'İstifadəçi dəvət et'" :back="route('settings.users.index')"
                   :subtitle="$user->exists ? $user->email : 'Mail ilə dəvət göndəriləcək, istifadəçi öz şifrəsini təyin edəcək.'"/>
    <form method="POST" action="{{ $user->exists ? route('settings.users.update', $user->id) : route('settings.users.store') }}" class="card p-6 max-w-2xl space-y-5">
        @csrf
        @if($user->exists) @method('PUT') @endif
        <div class="grid sm:grid-cols-2 gap-4">
            <x-input name="name" label="Ad, soyad" :value="$user->name" required/>
            <x-input name="email" type="email" label="Email" :value="$user->email" required/>
            <x-input name="position" label="Vəzifə" :value="$user->position"/>
            <x-input name="phone" label="Telefon" :value="$user->phone"/>
            <x-select name="role_id" label="Rol" :options="$roles" :value="$user->role_id" required placeholder="— seçin —" hint="Rolların icazələri «Rollar» bölməsində."/>
            @if($user->exists)
                <label class="flex items-center gap-2.5 text-sm self-center mt-6 cursor-pointer">
                    <input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="checkbox" @checked(old('is_active', $user->is_active))> Aktiv
                </label>
            @endif
        </div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('settings.users.index') }}" class="btn btn-secondary">Ləğv et</a>
            <button class="btn btn-primary"><x-icon :name="$user->exists ? 'check' : 'send'" class="size-4"/> {{ $user->exists ? 'Yadda saxla' : 'Dəvət göndər' }}</button>
        </div>
    </form>
</x-layouts.app>
