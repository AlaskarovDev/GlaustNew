<x-layouts.guest title="İki mərhələli təsdiq" heading="İki mərhələli təsdiq" subheading="Autentifikator tətbiqindəki 6 rəqəmli kodu daxil edin.">
    <form method="POST" action="{{ route('two-factor.verify') }}" class="space-y-5">
        @csrf
        <x-input name="code" label="Təsdiq kodu" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="text-center text-xl tracking-[.5em] font-mono h-12"/>
        <button class="btn btn-primary w-full h-11">Təsdiqlə</button>
    </form>
    <p class="mt-8 text-center text-sm"><a href="{{ route('login') }}" class="font-medium text-brand-ink hover:underline">Başqa hesabla daxil ol</a></p>
</x-layouts.guest>
