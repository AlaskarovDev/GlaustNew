<?php

namespace App\Support;

/** Texts used by resources/js/app.js; the layouts publish them as window.__i18n for the current locale. */
class JsTranslations
{
    public const KEYS = [
        'Əminsiniz?',
        'Bu əməliyyat geri qaytarıla bilməz.',
        'Tapşırıq tamamlandı',
        'Xatırlatma ertələndi',
        'Cəmi',
        'Məlumat yoxdur',
        'Panel yadda saxlanıldı',
        'Server xətası',
    ];

    public static function all(): array
    {
        if (app()->getLocale() === 'az') {
            return [];
        }
        $out = [];
        foreach (self::KEYS as $k) {
            $out[$k] = __($k);
        }

        return $out;
    }
}
