@props(['group', 'value', 'dot' => true])
<span {{ $attributes->merge(['class' => 'badge badge-'.status_color($group, $value).($dot ? ' badge-dot' : '')]) }}>{{ status_label($group, $value) }}</span>
