@php
    $events = [
        'login' => [__('Giriş'), 'green'], 'logout' => [__('Çıxış'), 'slate'], 'failed' => [__('Uğursuz cəhd'), 'rose'],
        'locked' => [__('Hesab bağlandı'), 'rose'], 'password_changed' => [__('Şifrə dəyişdi'), 'blue'], 'password_reset' => [__('Şifrə bərpa edildi'), 'blue'],
        'session_expired' => [__('Sessiya bitdi'), 'amber'], 'forced_logout' => [__('Məcburi çıxış'), 'violet'], 'two_factor_failed' => [__('2FA kodu səhv'), 'rose'],
    ];
    [$label, $color] = $events[$event] ?? [$event, 'slate'];
@endphp
<span class="badge badge-{{ $color }} badge-dot">{{ $label }}</span>
