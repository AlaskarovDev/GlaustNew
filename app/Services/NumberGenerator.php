<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Project;
use App\Models\Shipment;

/**
 * Document numbers from the company's numbering templates, e.g. "MQ-{Y}-{SEQ:4}" -> "MQ-2026-0007".
 * Tokens: {Y} year, {y} 2-digit year, {M} month, {SEQ:n} zero-padded sequence (per prefix).
 */
class NumberGenerator
{
    private const MODELS = [
        'project' => [Project::class, 'code'],
        'contract' => [Contract::class, 'number'],
        'shipment' => [Shipment::class, 'number'],
        'deal' => [\App\Models\Deal::class, 'code'],
    ];

    public function next(string $type): string
    {
        [$model, $column] = self::MODELS[$type];
        $template = (string) (tenant()?->setting("numbering.$type") ?? config("glaust.company_defaults.numbering.$type"));

        $now = now();
        $filled = strtr($template, ['{Y}' => $now->format('Y'), '{y}' => $now->format('y'), '{M}' => $now->format('m')]);

        if (! preg_match('/\{SEQ(?::(\d+))?\}/', $filled, $m)) {
            $filled .= '-{SEQ:4}';
            preg_match('/\{SEQ(?::(\d+))?\}/', $filled, $m);
        }
        $pad = (int) ($m[1] ?? 4);
        [$prefix, $suffix] = explode($m[0], $filled, 2);

        $existing = $model::withTrashed()
            ->where($column, 'like', addcslashes($prefix, '%_').'%')
            ->pluck($column);

        $max = 0;
        foreach ($existing as $value) {
            $tail = substr($value, strlen($prefix));
            if ($suffix !== '' && str_ends_with($tail, $suffix)) {
                $tail = substr($tail, 0, -strlen($suffix));
            }
            if (ctype_digit($tail)) {
                $max = max($max, (int) $tail);
            }
        }

        return $prefix.str_pad((string) ($max + 1), $pad, '0', STR_PAD_LEFT).$suffix;
    }
}
