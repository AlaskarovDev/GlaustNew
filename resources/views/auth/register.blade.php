<x-layouts.guest :title="__('Qeydiyyat')" :heading="__('Şirkətinizi qoşun')" :subheading="__('14 gün pulsuz sınaq. Kart məlumatı tələb olunmur.')">
    <form method="POST" action="{{ route('register') }}" class="space-y-5" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        <div class="grid sm:grid-cols-[1fr_150px] gap-4">
            <x-input name="company_name" :label="__('Şirkətin adı')" required :placeholder="__('Məs: Glaust MMC')"/>
            <x-input name="voen" :label="__('VÖEN')" inputmode="numeric" maxlength="10" :placeholder="__('10 rəqəm')"/>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <x-input name="name" :label="__('Ad, soyad')" required autocomplete="name"/>
            <x-input name="phone" :label="__('Telefon')" autocomplete="tel" placeholder="+994"/>
        </div>
        <x-input name="email" type="email" :label="__('Email')" required autocomplete="email"/>
        <div class="grid sm:grid-cols-2 gap-4">
            <x-input name="password" type="password" :label="__('Şifrə')" required autocomplete="new-password" :hint="__('Ən azı 8 simvol, hərf və rəqəm')"/>
            <x-input name="password_confirmation" type="password" :label="__('Şifrənin təkrarı')" required autocomplete="new-password"/>
        </div>
        <x-field name="terms">
            <label class="inline-flex items-start gap-2 text-sm text-ink-2 cursor-pointer">
                <input type="checkbox" name="terms" value="1" class="checkbox mt-0.5" @checked(old('terms'))>
                <span>{{ __('İstifadə şərtlərini və məlumatların emalı qaydalarını qəbul edirəm.') }}</span>
            </label>
        </x-field>
        <button class="btn btn-primary w-full h-11" :disabled="busy">{{ __('Hesab yarat') }}</button>
    </form>
    <p class="mt-8 text-center text-sm text-muted">{{ __('Artıq hesabınız var?') }} <a href="{{ route('login') }}" class="font-medium text-brand-ink hover:underline">{{ __('Daxil olun') }}</a></p>
</x-layouts.guest>
