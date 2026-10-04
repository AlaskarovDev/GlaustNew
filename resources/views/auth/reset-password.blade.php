<x-layouts.guest :title="__('Yeni şifrə')" :heading="__('Yeni şifrə təyin edin')">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-input name="email" type="email" :label="__('Email')" :value="$email" required autocomplete="username"/>
        <x-input name="password" type="password" :label="__('Yeni şifrə')" required autocomplete="new-password" :hint="__('Ən azı 8 simvol, hərf və rəqəm')"/>
        <x-input name="password_confirmation" type="password" :label="__('Şifrənin təkrarı')" required autocomplete="new-password"/>
        <button class="btn btn-primary w-full h-11">{{ __('Şifrəni yenilə') }}</button>
    </form>
</x-layouts.guest>
