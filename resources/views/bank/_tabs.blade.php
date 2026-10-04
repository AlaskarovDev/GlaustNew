<nav class="flex gap-6 border-b border-line mb-6 overflow-x-auto" aria-label="{{ __('Bank bölmələri') }}">
    <a href="{{ route('bank.transactions.index') }}" @class(['tab-link', 'is-active' => request()->routeIs('bank.transactions.*')])>{{ __('Əməliyyatlar') }}</a>
    <a href="{{ route('bank.exchanges.index') }}" @class(['tab-link', 'is-active' => request()->routeIs('bank.exchanges.*')])>{{ __('Valyuta alış-satışı') }}</a>
    <a href="{{ route('bank.accounts.index') }}" @class(['tab-link', 'is-active' => request()->routeIs('bank.accounts.*')])>{{ __('Bank hesabları') }}</a>
    @can('bank.import')
        <a href="{{ route('imports.index', ['type' => 'bank_transactions']) }}" class="tab-link">{{ __('Çıxarış importu') }}</a>
    @endcan
</nav>
