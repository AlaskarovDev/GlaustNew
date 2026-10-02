<x-layouts.app title="Mail ayarları">
    <x-page-header title="Tənzimləmələr" icon="settings"/>
    @include('settings._nav')
    <div class="grid lg:grid-cols-[minmax(0,1fr)_360px] gap-6 items-start max-w-5xl">
        <form method="POST" action="{{ route('settings.mail.update') }}" class="card p-6 space-y-5" autocomplete="off">
            @csrf @method('PUT')
            <div>
                <h2 class="text-base font-semibold">Şirkətin SMTP serveri</h2>
                <p class="text-sm text-muted mt-1">Xatırlatmalar, gündəlik xülasə və dəvətlər bu serverdən göndərilir. Boş qalarsa və ya xəta verərsə, platformanın ümumi mail serveri istifadə olunur.</p>
            </div>
            <div class="grid sm:grid-cols-3 gap-4">
                <x-input name="smtp_host" label="SMTP server" :value="$company->smtp_host" wrapper="sm:col-span-2" placeholder="smtp.hostinger.com"/>
                <x-input name="smtp_port" type="number" label="Port" :value="$company->smtp_port" placeholder="465"/>
                <x-input name="smtp_username" label="İstifadəçi adı" :value="$company->smtp_username" wrapper="sm:col-span-2" autocomplete="off"/>
                <x-select name="smtp_encryption" label="Şifrələmə" :options="['ssl' => 'SSL (465)', 'tls' => 'TLS / STARTTLS (587)']" :value="$company->smtp_encryption" placeholder="Yoxdur"/>
                <x-input name="smtp_password" type="password" label="Şifrə" wrapper="sm:col-span-3" autocomplete="new-password"
                         :hint="$company->smtp_password ? 'Şifrə saxlanılıb (şifrələnmiş). Dəyişmək istəmirsinizsə boş saxlayın.' : null"/>
                <x-input name="smtp_from_address" type="email" label="Göndərən email" :value="$company->smtp_from_address" wrapper="sm:col-span-2"/>
                <x-input name="smtp_from_name" label="Göndərən adı" :value="$company->smtp_from_name" :placeholder="$company->name"/>
            </div>
            <div class="flex justify-end"><button class="btn btn-primary" @cannot('settings.update') disabled @endcannot>Yadda saxla</button></div>
        </form>
        <form method="POST" action="{{ route('settings.mail.test') }}" class="card p-6 space-y-4">
            @csrf
            <h2 class="text-base font-semibold flex items-center gap-2"><x-icon name="send" class="size-4 text-brand"/> Test mail</h2>
            <p class="text-sm text-muted">Yalnız şirkətin SMTP-si ilə göndərilir (ehtiyat server istifadə olunmur), beləliklə ayarların özü yoxlanır.</p>
            <x-input name="to" type="email" label="Kimə" :value="auth()->user()->email" required/>
            <button class="btn btn-secondary w-full" @cannot('settings.update') disabled @endcannot>Test mail göndər</button>
            <p class="text-xs text-muted">Server «qəbul etdi» cavabı mailin qutuya çatdığını sübut etmir — qutunu və spam qovluğunu yoxlayın.</p>
        </form>
    </div>
</x-layouts.app>
