@php
    $events = [
        'login' => ['Giriş', 'green'], 'logout' => ['Çıxış', 'slate'], 'failed' => ['Uğursuz cəhd', 'rose'],
        'locked' => ['Hesab bağlandı', 'rose'], 'password_changed' => ['Şifrə dəyişdi', 'blue'], 'password_reset' => ['Şifrə bərpa edildi', 'blue'],
        'session_expired' => ['Sessiya bitdi', 'amber'], 'forced_logout' => ['Məcburi çıxış', 'violet'], 'two_factor_failed' => ['2FA kodu səhv', 'rose'],
    ];
    [$label, $color] = $events[$event] ?? [$event, 'slate'];
@endphp
<span class="badge badge-{{ $color }} badge-dot">{{ $label }}</span>
