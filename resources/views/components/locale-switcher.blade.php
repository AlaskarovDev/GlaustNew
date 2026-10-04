{{-- Interface language: AZ / RU / EN. Saved on the user (or in the session for guests). --}}
@props(['compact' => false])
<form method="POST" action="{{ route('locale') }}" {{ $attributes->class(['grid grid-cols-3 gap-1']) }} aria-label="{{ __('Dil') }}">
    @csrf
    @foreach(config('glaust.locales') as $code => $name)
        <button name="locale" value="{{ $code }}" title="{{ $name }}" lang="{{ $code }}"
                @class(['h-8 rounded-lg text-xs font-semibold uppercase tracking-wide transition-colors',
                        'bg-brand-soft text-brand-ink' => app()->getLocale() === $code,
                        'text-muted hover:bg-surface-2 hover:text-ink' => app()->getLocale() !== $code])
                @if(app()->getLocale() === $code) aria-current="true" @endif>
            {{ $compact ? $code : $name }}
        </button>
    @endforeach
</form>
