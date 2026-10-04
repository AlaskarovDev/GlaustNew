@props(['action', 'message' => __('Bu qeyd silinəcək. Davam edilsin?'), 'label' => __('Sil'), 'icon' => true, 'button' => 'btn btn-ghost btn-sm text-danger hover:!bg-danger-soft'])
<form method="POST" action="{{ $action }}" data-confirm="{{ $message }}" data-confirm-title="Silinsin?" {{ $attributes->only('class') }}>
    @csrf
    @method('DELETE')
    <button type="submit" class="{{ $button }}" title="{{ $label }}">
        @if($icon)<x-icon name="trash" class="size-4"/>@endif
        @if($label)<span>{{ $label }}</span>@endif
    </button>
</form>
