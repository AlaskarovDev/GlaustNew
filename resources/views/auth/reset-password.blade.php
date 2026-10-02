<x-layouts.guest title="Yeni şifrə" heading="Yeni şifrə təyin edin">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-input name="email" type="email" label="Email" :value="$email" required autocomplete="username"/>
        <x-input name="password" type="password" label="Yeni şifrə" required autocomplete="new-password" hint="Ən azı 8 simvol, hərf və rəqəm"/>
        <x-input name="password_confirmation" type="password" label="Şifrənin təkrarı" required autocomplete="new-password"/>
        <button class="btn btn-primary w-full h-11">Şifrəni yenilə</button>
    </form>
</x-layouts.guest>
