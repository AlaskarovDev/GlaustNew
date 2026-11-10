<?php

namespace App\Console\Commands;

use App\Support\Exchanges\Exchanges;
use Illuminate\Console\Command;

/** Read only: whether each «Birja» source answers from this server — its date, how many currencies, USD/EUR and a 30-day history. */
class ExchangesCheckCommand extends Command
{
    protected $signature = 'glaust:exchanges-check';

    protected $description = 'Birja mənbələrinin bu serverdən cavab verib-vermədiyini yoxlayır';

    public function handle(): int
    {
        foreach (Exchanges::all() as $key => $s) {
            if ($key === 'cbar') {
                continue;   // the system's own data, needs a company's tenant for nothing — CBAR is checked by glaust:cbar-fetch
            }
            $t = microtime(true);
            try {
                $l = $s->latest();
                $code = isset($l['rates']['EUR']) ? 'EUR' : (string) array_key_first($l['rates']);
                $h = $code ? $s->history($code, 30) : [];
                $this->line(sprintf('%-8s ok  %s  %d valyuta  USD/%s %s  tarixçə %d gün  %.1fs', $key, $l['date'] ?? '—', count($l['rates']), $code,
                    isset($l['rates'][$code]) ? round($l['rates'][$code], 4) : '—', count($h), microtime(true) - $t));
            } catch (\Throwable $e) {
                $this->line(sprintf('%-8s XƏTA %s', $key, mb_substr($e->getMessage(), 0, 160)));
            }
        }

        return self::SUCCESS;
    }
}
