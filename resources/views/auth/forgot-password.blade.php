<x-layouts.guest title="Şifrənin bərpası" heading="Şifrəni unutmusunuz?" subheading="Emailinizi yazın, şifrəni yeniləmək üçün link göndərək.">
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf
        <x-input name="email" type="email" label="Email" required autofocus autocomplete="username"/>
        <button class="btn btn-primary w-full h-11">Link göndər</button>
    </form>
    <p class="mt-8 text-center text-sm"><a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 font-medium text-brand-ink hover:underline"><x-icon name="arrow-left" class="size-4"/> Girişə qayıt</a></p>
</x-layouts.guest>
