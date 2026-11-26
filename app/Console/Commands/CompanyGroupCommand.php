<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Support\Tenancy\CompanyGroup;
use Illuminate\Console\Command;

/**
 * Şirkət qrupu: without --link lists the companies with their group and users; --link=4,6 puts those companies into one
 * group and makes every user of each a user of all (--dry: only says what would be added).
 */
class CompanyGroupCommand extends Command
{
    protected $signature = 'glaust:company-group {--link= : company ids, comma separated} {--dry}';

    protected $description = 'Şirkətləri bir qrupa birləşdirir: istifadəçiləri bütün şirkətlərdə olur';

    public function handle(): int
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('link')))));
        if ($ids) {
            $companies = Company::whereIn('id', $ids)->get()->sortBy(fn ($c) => array_search($c->id, $ids))->values();
            if ($companies->count() !== count($ids) || count($ids) < 2) {
                $this->error('Ən azı iki mövcud şirkət lazımdır.');

                return self::FAILURE;
            }
            if ($this->option('dry')) {
                $this->info('Yoxlama: '.$companies->pluck('name')->implode(' + ').' bir qrup olacaq.');
            } else {
                CompanyGroup::link(...$companies->all());
                $this->info('Birləşdirildi: '.$companies->pluck('name')->implode(' + '));
            }
        }
        foreach (Company::orderBy('id')->get() as $c) {
            $this->line("#{$c->id} {$c->name} · qrup ".($c->group_id ?? '—'));
            foreach (CompanyUser::with('user', 'role')->where('company_id', $c->id)->get() as $m) {
                $this->line('    '.($m->user?->email ?? '?').' · '.($m->role?->name ?? '—').($m->user?->is_active ? '' : ' (deaktiv)'));
            }
        }

        return self::SUCCESS;
    }
}
