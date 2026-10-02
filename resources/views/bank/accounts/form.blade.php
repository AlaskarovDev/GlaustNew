<x-layouts.app :title="$account->exists ? $account->name : 'Yeni bank hesabı'">
    <x-page-header :title="$account->exists ? $account->name : 'Yeni bank hesabı'" :back="route('bank.accounts.index')"/>
    <form method="POST" action="{{ $account->exists ? route('bank.accounts.update', $account) : route('bank.accounts.store') }}" class="card p-6 max-w-2xl space-y-5">
        @csrf
        @if($account->exists) @method('PUT') @endif
        <div class="grid sm:grid-cols-2 gap-4">
            <x-input name="name" label="Hesabın adı" :value="$account->name" required placeholder="Məs: Əsas AZN hesabı"/>
            <x-input name="bank_name" label="Bank" :value="$account->bank_name" required placeholder="Məs: Kapital Bank"/>
            <x-input name="iban" label="IBAN" :value="$account->iban" wrapper="sm:col-span-2" class="font-mono uppercase"/>
            <x-select name="currency" label="Valyuta" :options="array_combine(config('glaust.currencies'), config('glaust.currencies'))" :value="$account->currency" required/>
            <x-input name="opening_balance" label="Başlanğıc qalıq" :value="$account->opening_balance" required class="font-mono text-right" inputmode="decimal"/>
            <x-input name="opening_date" type="date" label="Qalıq tarixi" :value="$account->opening_date"/>
            <label class="flex items-center gap-2.5 text-sm self-end h-10 cursor-pointer">
                <input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="checkbox" @checked(old('is_active', $account->is_active))> Aktiv
            </label>
        </div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('bank.accounts.index') }}" class="btn btn-secondary">Ləğv et</a>
            <button class="btn btn-primary"><x-icon name="check" class="size-4"/> Yadda saxla</button>
        </div>
    </form>
</x-layouts.app>
