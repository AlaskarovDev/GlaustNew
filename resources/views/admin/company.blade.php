<x-layouts.admin :title="$company->name">
    <x-page-header :title="$company->name" :subtitle="($company->voen ? 'VÖEN '.$company->voen.' · ' : '').'qeydiyyat '.azdate($company->created_at)" :back="route('admin.companies.index')"/>
    <div class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
        <div class="space-y-6 min-w-0">
            {{-- Users of this company: add, edit (password, phone, position, role), deactivate --}}
            <section class="card overflow-hidden" x-data="{ adding: {{ $errors->any() && old('_form') === 'new' ? 'true' : 'false' }} }">
                <header class="px-5 h-14 flex items-center justify-between border-b border-line">
                    <h2 class="text-sm font-semibold">İstifadəçilər ({{ $users->count() }})</h2>
                    <button type="button" class="btn btn-primary btn-sm" @click="adding = !adding"><x-icon name="plus" class="size-4"/> <span x-text="adding ? 'Bağla' : 'İstifadəçi əlavə et'"></span></button>
                </header>
                <form method="POST" action="{{ route('admin.companies.users.store', $company) }}" x-show="adding" x-collapse @unless($errors->any() && old('_form') === 'new') x-cloak @endunless class="p-5 border-b border-line bg-surface-2/50 grid sm:grid-cols-2 gap-4">
                    @csrf
                    <input type="hidden" name="_form" value="new">
                    <x-input name="name" fresh :id="'new'.'-name'" :label="__('Ad, soyad')" :value="old('_form') === 'new' ? old('name') : ''" required/>
                    <x-input name="email" fresh :id="'new'.'-email'" type="email" :label="__('Email (login)')" :value="old('_form') === 'new' ? old('email') : ''" required/>
                    <x-input name="password" fresh :id="'new'.'-password'" type="password" :label="__('Şifrə')" required :hint="__('Ən azı 8 simvol, hərf və rəqəm')"/>
                    <x-input name="phone" fresh :id="'new'.'-phone'" :label="__('Telefon')" :value="old('_form') === 'new' ? old('phone') : ''"/>
                    <x-input name="position" fresh :id="'new'.'-position'" :label="__('Vəzifə')" :value="old('_form') === 'new' ? old('position') : ''"/>
                    <x-select name="role_id" fresh :id="'new'.'-role_id'" :label="__('Rol')" :options="$roles->pluck('name', 'id')->all()" :value="old('_form') === 'new' ? old('role_id') : $roles->first()?->id" required/>
                    <div class="sm:col-span-2"><button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Əlavə et') }}</button></div>
                </form>
                @if($users->isEmpty())
                    <p class="px-5 py-6 text-sm text-muted">{{ __('Bu şirkətdə hələ istifadəçi yoxdur — «İstifadəçi əlavə et».') }}</p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach($users as $u)
                            @php $mine = old('_form') === 'user-'.$u->id; @endphp
                            <li x-data="{ edit: {{ $mine && $errors->any() ? 'true' : 'false' }} }">
                                <div class="flex flex-wrap items-center gap-3 px-5 py-3">
                                    <x-avatar :user="$u" size="sm"/>
                                    <div class="min-w-0 flex-1">
                                        <div class="font-medium">{{ $u->name }} @unless($u->is_active)<span class="badge badge-slate ml-1">{{ __('Deaktiv') }}</span>@endunless</div>
                                        <div class="text-xs text-muted">{{ $u->email }} · {{ $u->role?->name }}{{ $u->position ? ' · '.$u->position : '' }}{{ $u->phone ? ' · '.$u->phone : '' }}</div>
                                    </div>
                                    <span class="text-xs text-faint font-mono">{{ $u->last_login_at ? azdate($u->last_login_at, true) : 'giriş olmayıb' }}</span>
                                    <button type="button" class="btn btn-secondary btn-sm" @click="edit = !edit"><x-icon name="pencil" class="size-3.5"/> <span x-text="edit ? 'Bağla' : 'Redaktə'"></span></button>
                                </div>
                                <form method="POST" action="{{ route('admin.companies.users.update', [$company, $u]) }}" x-show="edit" x-collapse @unless($mine && $errors->any()) x-cloak @endunless class="px-5 pb-5 grid sm:grid-cols-2 gap-4">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="_form" value="user-{{ $u->id }}">
                                    <x-input name="name" fresh :id="'u'.$u->id.'-name'" :label="__('Ad, soyad')" :value="$mine ? old('name') : $u->name" required/>
                                    <x-input name="email" fresh :id="'u'.$u->id.'-email'" type="email" :label="__('Email (login)')" :value="$mine ? old('email') : $u->email" required/>
                                    <x-input name="password" fresh :id="'u'.$u->id.'-password'" type="password" :label="__('Yeni şifrə')" :hint="__('Boş saxlayın — şifrə dəyişmir')"/>
                                    <x-input name="phone" fresh :id="'u'.$u->id.'-phone'" :label="__('Telefon')" :value="$mine ? old('phone') : $u->phone"/>
                                    <x-input name="position" fresh :id="'u'.$u->id.'-position'" :label="__('Vəzifə')" :value="$mine ? old('position') : $u->position"/>
                                    <x-select name="role_id" fresh :id="'u'.$u->id.'-role_id'" :label="__('Rol')" :options="$roles->pluck('name', 'id')->all()" :value="$mine ? old('role_id') : $u->role_id" required/>
                                    <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="checkbox" @checked($mine ? old('is_active') : $u->is_active)> {{ __('Aktiv (daxil ola bilər)') }}</label>
                                    <div class="sm:col-span-2"><button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button></div>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
            <section class="card overflow-hidden">
                <header class="px-5 h-14 flex items-center border-b border-line"><h2 class="text-sm font-semibold">{{ __('Son giriş hadisələri') }}</h2></header>
                <table class="table-g table-stack">
                    <tbody>@foreach($logins as $l)
                        <tr><td data-label="Tarix" class="font-mono text-xs">{{ azdate($l->created_at, true) }}</td><td data-label="Email">{{ $l->email }}</td>
                            <td data-label="Hadisə">@include('settings.logs._event', ['event' => $l->event])</td><td data-label="IP" class="font-mono text-xs">{{ $l->ip_address }}</td></tr>
                    @endforeach</tbody>
                </table>
            </section>
        </div>
        <aside class="space-y-6">
            <form method="POST" action="{{ route('admin.companies.update', $company) }}" class="card p-5 space-y-4">
                @csrf @method('PUT')
                <h2 class="text-sm font-semibold">{{ __('Abunə') }}</h2>
                <x-select name="plan_id" :label="__('Tarif')" :options="$plans->pluck('name', 'id')->all()" :value="$company->plan_id" placeholder="—"/>
                <x-select name="subscription_status" :label="__('Status')" :options="status_options('subscription')" :value="$company->subscription_status" required/>
                <x-input name="trial_ends_at" type="date" :label="__('Sınaq bitir')" :value="$company->trial_ends_at"/>
                <x-input name="subscription_ends_at" type="date" :label="__('Abunə bitir')" :value="$company->subscription_ends_at" :hint="__('Boş — müddətsiz')"/>
                <button class="btn btn-primary w-full">{{ __('Yadda saxla') }}</button>
            </form>
            <section class="card p-5 text-sm space-y-2">
                <div class="flex justify-between"><span class="text-muted">{{ __('Fayllar') }}</span><span class="font-mono">{{ round($storage / 1048576, 1) }} MB{{ $company->plan ? ' / '.$company->plan->max_storage_mb.' MB' : '' }}</span></div>
                <div class="flex justify-between"><span class="text-muted">{{ __('Öz SMTP') }}</span><span>{{ $company->hasOwnSmtp() ? 'bəli' : 'xeyr' }}</span></div>
                <div class="flex justify-between"><span class="text-muted">{{ __('Email') }}</span><span>{{ $company->email }}</span></div>
            </section>
        </aside>
    </div>
</x-layouts.admin>
