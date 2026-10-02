<x-layouts.app title="Profil">
    <x-page-header title="Profil və təhlükəsizlik" icon="user" :subtitle="$user->email.' · '.$user->role?->name"/>

    @php $tab = request('tab', $errors->has('current_password') || $errors->has('code') || $errors->has('password') ? 'security' : 'profile'); @endphp
    <div x-data="{ tab: @js($tab) }">
        <nav class="flex gap-6 border-b border-line mb-6 overflow-x-auto" aria-label="Bölmələr">
            <button type="button" class="tab-link" :class="tab === 'profile' && 'is-active'" @click="tab = 'profile'">Profil və bildirişlər</button>
            <button type="button" class="tab-link" :class="tab === 'security' && 'is-active'" @click="tab = 'security'">Təhlükəsizlik</button>
        </nav>

        {{-- Profile --}}
        <div x-show="tab === 'profile'" class="grid lg:grid-cols-[minmax(0,1fr)_320px] gap-6 items-start">
            <form method="POST" action="{{ route('profile.update') }}" class="card p-6 space-y-5">
                @csrf @method('PUT')
                <div class="flex items-center gap-4">
                    <x-avatar :user="$user" size="xl"/>
                    <div>
                        <div class="font-semibold">{{ $user->name }}</div>
                        <div class="text-sm text-muted">{{ $user->company?->name }}</div>
                    </div>
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <x-input name="name" label="Ad, soyad" :value="$user->name" required/>
                    <x-input name="position" label="Vəzifə" :value="$user->position"/>
                    <x-input name="phone" label="Telefon" :value="$user->phone"/>
                    <x-input name="email_display" label="Email" :value="$user->email" disabled hint="Emaili administrator dəyişə bilər."/>
                </div>
                <fieldset class="rounded-xl border border-line p-4 space-y-3">
                    <legend class="px-1 text-sm font-semibold">Mail bildirişləri</legend>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="hidden" name="daily_digest" value="0">
                        <input type="checkbox" name="daily_digest" value="1" class="checkbox mt-0.5" @checked(old('daily_digest', $user->daily_digest))>
                        <span><span class="block text-sm font-medium">Gündəlik xülasə</span><span class="block text-xs text-muted">Hər səhər bugünkü və gecikmiş tapşırıqlar, bitən müqavilələr və ödənişlər.</span></span>
                    </label>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="hidden" name="email_reminders" value="0">
                        <input type="checkbox" name="email_reminders" value="1" class="checkbox mt-0.5" @checked(old('email_reminders', $user->email_reminders))>
                        <span><span class="block text-sm font-medium">Xatırlatmalar maillə</span><span class="block text-xs text-muted">Xatırlatmanın vaxtı çatanda mail də göndərilsin.</span></span>
                    </label>
                </fieldset>
                <div class="flex justify-end"><button class="btn btn-primary">Yadda saxla</button></div>
            </form>

            <aside class="card p-5 text-sm space-y-3">
                <div class="font-semibold">Hesab</div>
                <div class="flex justify-between"><span class="text-muted">Rol</span><span>{{ $user->role?->name }}</span></div>
                <div class="flex justify-between"><span class="text-muted">Son giriş</span><span class="font-mono text-xs">{{ azdate($user->last_login_at, true) }}</span></div>
                <div class="flex justify-between"><span class="text-muted">2FA</span>
                    <span @class(['badge', 'badge-green' => $user->hasTwoFactor(), 'badge-slate' => ! $user->hasTwoFactor()])>{{ $user->hasTwoFactor() ? 'Aktiv' : 'Söndürülüb' }}</span></div>
            </aside>
        </div>

        {{-- Security --}}
        <div x-show="tab === 'security'" x-cloak class="grid lg:grid-cols-2 gap-6 items-start">
            <form method="POST" action="{{ route('profile.password') }}" class="card p-6 space-y-4">
                @csrf @method('PUT')
                <h2 class="text-base font-semibold">Şifrəni dəyiş</h2>
                <x-input name="current_password" type="password" label="Cari şifrə" required autocomplete="current-password"/>
                <x-input name="password" type="password" label="Yeni şifrə" required autocomplete="new-password" hint="Ən azı 8 simvol, hərf və rəqəm. Digər cihazlardakı sessiyalar bağlanacaq."/>
                <x-input name="password_confirmation" type="password" label="Yeni şifrənin təkrarı" required autocomplete="new-password"/>
                <div class="flex justify-end"><button class="btn btn-primary">Şifrəni yenilə</button></div>
            </form>

            <section class="card p-6">
                <h2 class="text-base font-semibold flex items-center gap-2"><x-icon name="shield" class="size-5 text-brand"/> İki mərhələli təsdiq (2FA)</h2>
                @if($user->hasTwoFactor())
                    <p class="mt-2 text-sm text-muted">Hər girişdə autentifikator tətbiqindəki kod soruşulur.</p>
                    <form method="POST" action="{{ route('profile.2fa.disable') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
                        @csrf @method('DELETE')
                        <input type="password" name="current_password" placeholder="Şifrəniz" class="input" required aria-label="Şifrə">
                        <button class="btn btn-danger shrink-0">2FA-nı söndür</button>
                    </form>
                @elseif($qr)
                    <p class="mt-2 text-sm text-muted">Google Authenticator, Microsoft Authenticator və ya oxşar tətbiqlə QR kodu skan edin, sonra 6 rəqəmli kodu daxil edin.</p>
                    <div class="mt-4 flex flex-col sm:flex-row gap-5 items-start">
                        <div class="p-3 bg-white rounded-xl border border-line shrink-0">{!! $qr !!}</div>
                        <form method="POST" action="{{ route('profile.2fa.confirm') }}" class="space-y-3 w-full">
                            @csrf
                            <x-input name="code" label="Kod" required inputmode="numeric" maxlength="6" autocomplete="one-time-code" class="font-mono tracking-[.4em] text-center text-lg"/>
                            <button class="btn btn-primary w-full">Təsdiqlə və aktivləşdir</button>
                            <p class="text-xs text-muted break-all">Əl ilə açar: <span class="font-mono">{{ $user->two_factor_secret }}</span></p>
                        </form>
                    </div>
                @else
                    <p class="mt-2 text-sm text-muted">Şifrədən əlavə telefonunuzdakı kodla qorunma. Tövsiyə olunur.</p>
                    <form method="POST" action="{{ route('profile.2fa.enable') }}" class="mt-4">@csrf
                        <button class="btn btn-primary"><x-icon name="key" class="size-4"/> 2FA-nı qur</button>
                    </form>
                @endif
            </section>

            <section class="card overflow-hidden lg:col-span-2">
                <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-b border-line">
                    <div>
                        <h2 class="text-base font-semibold">Aktiv sessiyalar</h2>
                        <p class="text-xs text-muted">Hesabınıza daxil olunmuş cihazlar</p>
                    </div>
                    @if($sessions->count() > 1)
                        <form method="POST" action="{{ route('profile.sessions.others') }}" class="flex gap-2">
                            @csrf
                            <input type="password" name="password" placeholder="Şifrəniz" class="input !h-9 !w-44" required aria-label="Şifrə">
                            <button class="btn btn-secondary btn-sm h-9">Digərlərini bağla</button>
                        </form>
                    @endif
                </header>
                <ul class="divide-y divide-line">
                    @foreach($sessions as $s)
                        <li class="flex items-center gap-4 px-6 py-3">
                            <span class="grid place-items-center size-9 rounded-lg bg-surface-2 text-muted"><x-icon name="monitor" class="size-4"/></span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium">{{ $s->device }} @if($s->current)<span class="badge badge-teal ml-1">Bu cihaz</span>@endif</div>
                                <div class="text-xs text-muted font-mono">{{ $s->ip }} · son fəaliyyət {{ $s->last->diffForHumans() }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="card overflow-hidden lg:col-span-2">
                <header class="px-6 py-4 border-b border-line"><h2 class="text-base font-semibold">Son girişlər</h2></header>
                <table class="table-g table-stack">
                    <thead><tr><th>Hadisə</th><th>Tarix</th><th>IP</th><th>Cihaz</th><th class="!text-right">Müddət</th></tr></thead>
                    <tbody>
                    @foreach($logins as $l)
                        <tr>
                            <td data-label="Hadisə">@include('settings.logs._event', ['event' => $l->event])</td>
                            <td data-label="Tarix" class="font-mono text-xs">{{ azdate($l->created_at, true) }}</td>
                            <td data-label="IP" class="font-mono text-xs">{{ $l->ip_address }}</td>
                            <td data-label="Cihaz">{{ $l->device }}</td>
                            <td data-label="Müddət" class="num text-xs">{{ $l->duration_seconds !== null ? gmdate($l->duration_seconds >= 3600 ? 'G:i:s' : 'i:s', $l->duration_seconds) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</x-layouts.app>
