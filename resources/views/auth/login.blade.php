<x-layouts.guest title="Giriş" heading="Xoş gəlmisiniz" subheading="Hesabınıza daxil olun.">
    <form method="POST" action="{{ route('login') }}" class="space-y-5" x-data="{ show: false, busy: false }" @submit="busy = true">
        @csrf
        <x-input name="email" type="email" label="Email" required autocomplete="username" autofocus placeholder="ad@sirket.az"/>
        <x-field label="Şifrə" name="password" :required="true">
            <div class="relative">
                <input id="password" name="password" :type="show ? 'text' : 'password'" type="password" required autocomplete="current-password"
                       class="input pr-11 @error('password') is-invalid @enderror">
                <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 grid place-items-center w-10 text-muted hover:text-ink" :aria-label="show ? 'Şifrəni gizlə' : 'Şifrəni göstər'">
                    <x-icon name="eye" class="size-4" x-show="!show"/>
                    <x-icon name="eye-off" class="size-4" x-show="show" x-cloak/>
                </button>
            </div>
        </x-field>
        <div class="flex items-center justify-between">
            <label class="inline-flex items-center gap-2 text-sm text-ink-2 cursor-pointer">
                <input type="checkbox" name="remember" value="1" class="checkbox" @checked(old('remember'))> Məni xatırla
            </label>
            <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-ink hover:underline">Şifrəni unutmusunuz?</a>
        </div>
        <button class="btn btn-primary w-full h-11" :disabled="busy">
            <span x-show="busy" x-cloak class="size-4 rounded-full border-2 border-current border-t-transparent animate-spin"></span>
            Daxil ol
        </button>
    </form>
    @if(config('glaust.registration_open'))
        <p class="mt-8 text-center text-sm text-muted">
            Şirkətiniz hələ qoşulmayıb? <a href="{{ route('register') }}" class="font-medium text-brand-ink hover:underline">Pulsuz sınağa başlayın</a>
        </p>
    @endif
</x-layouts.guest>
