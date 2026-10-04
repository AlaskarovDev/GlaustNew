@php
    $granted = old('permissions', $role->permissions ?? []);
    $has = function (string $module, string $action) use ($granted) {
        return in_array("$module.$action", $granted, true) || in_array("$module.*", $granted, true) || in_array('*', $granted, true);
    };
@endphp
<x-layouts.app :title="$role->exists ? $role->name : 'Yeni rol'">
    <x-page-header :title="$role->exists ? 'Rol: '.$role->name : 'Yeni rol'" :back="route('settings.roles.index')" :subtitle="__('Hər modul üzrə hansı əməliyyatlara icazə verildiyini seçin')"/>
    <form method="POST" action="{{ $role->exists ? route('settings.roles.update', $role) : route('settings.roles.store') }}" class="space-y-6 max-w-5xl">
        @csrf
        @if($role->exists) @method('PUT') @endif
        <div class="card p-6 max-w-md"><x-input name="name" :label="__('Rolun adı')" :value="$role->name" required/></div>
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table-g">
                    <thead><tr><th>{{ __('Modul') }}</th>@foreach(config('glaust.actions') as $a => $label)<th class="!text-center">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach(config('glaust.modules') as $module => $def)
                        <tr x-data>
                            <td class="font-medium text-ink whitespace-nowrap">
                                <button type="button" class="hover:text-brand-ink" @click="const boxes = [...$el.closest('tr').querySelectorAll('input[type=checkbox]')]; const on = boxes.some(b => !b.checked); boxes.forEach(b => b.checked = on)" title="{{ __('Sətri tam seç / təmizlə') }}">{{ $def['label'] }}</button>
                            </td>
                            @foreach(array_keys(config('glaust.actions')) as $action)
                                <td class="text-center">
                                    @if(in_array($action, $def['actions'], true))
                                        <input type="checkbox" name="permissions[]" value="{{ $module }}.{{ $action }}" class="checkbox !size-[18px]" @checked($has($module, $action)) aria-label="{{ $def['label'] }}: {{ config('glaust.actions.'.$action) }}">
                                    @else
                                        <span class="text-faint">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="px-4 py-3 border-t border-line text-xs text-muted">{{ __('Modulun adına klikləyərək bütün sətri seçə bilərsiniz. Hər hansı əməliyyat seçilibsə, «Baxış» avtomatik əlavə olunur. Tarifinizdə olmayan modullar icazə verilsə də görünməz.') }}</p>
        </div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('settings.roles.index') }}" class="btn btn-secondary">{{ __('Ləğv et') }}</a>
            <button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button>
        </div>
    </form>
</x-layouts.app>
