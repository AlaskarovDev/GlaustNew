<nav class="flex gap-6 border-b border-line mb-6 overflow-x-auto" aria-label="{{ __('Xərclər bölmələri') }}">
    <a href="{{ route('expenses.index') }}" @class(['tab-link', 'is-active' => request()->routeIs('expenses.index', 'expenses.create', 'expenses.edit')])>{{ __('Xərclər') }}</a>
    <a href="{{ route('expenses.categories') }}" @class(['tab-link', 'is-active' => request()->routeIs('expenses.categories')])>{{ __('Xərc kateqoriyaları') }}</a>
</nav>
