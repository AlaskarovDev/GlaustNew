<?php

namespace App\Support;

use Illuminate\Support\Facades\Vite;

/**
 * One Content-Security-Policy for the header and the <meta> tag.
 * The meta copy matters on Hostinger: its CDN replaces the CSP response header with
 * its own "upgrade-insecure-requests", so only the in-page policy reaches the browser.
 * style-src needs 'unsafe-inline' (Alpine / ApexCharts set inline styles); script-src
 * needs 'unsafe-eval' (Alpine evaluates expressions with new Function()).
 */
class Csp
{
    public static function policy(bool $forMeta = false): string
    {
        $nonce = Vite::cspNonce();
        $rules = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            'upgrade-insecure-requests',
        ];
        if (! $forMeta) {
            $rules[] = "frame-ancestors 'self'"; // ignored in <meta>; X-Frame-Options covers it
        }

        return implode('; ', $rules);
    }
}
