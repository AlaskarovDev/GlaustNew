@php
    [$label, $tone] = [
        'nosale' => [__('Proforma yoxdur'), 'slate'],
        'forecast' => [__('Proqnoz'), 'amber'],
        'act' => [__('Akt daxil edilib'), 'blue'],
        'settling' => [__('Ödənişlər davam edir'), 'violet'],
        'closed' => [__('Bağlanıb'), 'green'],
    ][$stage];
@endphp
<span class="badge badge-{{ $tone }} whitespace-nowrap">{{ $label }}</span>
