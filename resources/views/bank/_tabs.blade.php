<nav class="flex gap-6 border-b border-line mb-6 overflow-x-auto" aria-label="Bank bölmələri">
    <a href="{{ route('bank.transactions.index') }}" @class(['tab-link', 'is-active' => request()->routeIs('bank.transactions.*')])>Əməliyyatlar</a>
    <a href="{{ route('bank.accounts.index') }}" @class(['tab-link', 'is-active' => request()->routeIs('bank.accounts.*')])>Bank hesabları</a>
    @can('bank.import')
        <a href="{{ route('imports.index', ['type' => 'bank_transactions']) }}" class="tab-link">Çıxarış importu</a>
    @endcan
</nav>
