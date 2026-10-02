<x-layouts.guest title="Dəvət" heading="Komandaya xoş gəldiniz" :subheading="$user->company?->name.' sizi Glaust MS-ə dəvət edib. Hesabınız üçün şifrə təyin edin.'">
    <div class="card p-4 flex items-center gap-3 mb-6">
        <x-avatar :user="$user"/>
        <div class="min-w-0">
            <div class="text-sm font-semibold truncate">{{ $user->name }}</div>
            <div class="text-xs text-muted truncate">{{ $user->email }}</div>
        </div>
    </div>
    <form method="POST" action="{{ route('invitation.accept', $token) }}" class="space-y-5">
        @csrf
        <x-input name="password" type="password" label="Şifrə" required autocomplete="new-password" hint="Ən azı 8 simvol, hərf və rəqəm"/>
        <x-input name="password_confirmation" type="password" label="Şifrənin təkrarı" required autocomplete="new-password"/>
        <button class="btn btn-primary w-full h-11">Hesabı aktivləşdir</button>
    </form>
</x-layouts.guest>
