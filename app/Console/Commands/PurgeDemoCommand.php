<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the demo companies created by glaust:demo (their users sign in with *.demo addresses)
 * together with everything they own. Dry run unless --force. With --force the SQLite database is
 * first copied with VACUUM INTO (storage/app/backups), then every row of those companies is deleted
 * table by table, rows left pointing at deleted parents are removed, and the foreign keys are checked.
 */
class PurgeDemoCommand extends Command
{
    protected $signature = 'glaust:purge-demo {--force : Actually delete (otherwise only report)}';

    protected $description = 'Demo şirkətlərini (.demo istifadəçili) bütün məlumatları ilə silir';

    public function handle(): int
    {
        $ids = DB::table('users')->where('email', 'like', '%.demo')->whereNotNull('company_id')->distinct()->pluck('company_id')->all();
        $companies = Company::whereIn('id', $ids)->get(['id', 'name']);
        if ($companies->isEmpty()) {
            $this->info('Demo şirkəti yoxdur.');

            return self::SUCCESS;
        }
        foreach ($companies as $c) {
            $this->line("• #{$c->id} {$c->name}");
        }
        $tables = $this->tables();
        $scoped = array_values(array_filter($tables, fn ($t) => $t !== 'companies' && Schema::hasColumn($t, 'company_id')));
        $this->line('Şirkət sütunu olan cədvəllər: '.count($scoped));
        if (! $this->option('force')) {
            $this->warn('Yoxlama rejimi — heç nə silinmədi. Silmək üçün --force.');

            return self::SUCCESS;
        }

        $sqlite = DB::getDriverName() === 'sqlite';
        if ($sqlite) {
            Storage::disk('local')->makeDirectory('backups');
            $backup = Storage::disk('local')->path('backups/before-purge-demo-'.now()->format('Ymd-His').'.sqlite');
            DB::statement('VACUUM INTO ?', [$backup]);
            $this->info('Ehtiyat nüsxə: '.$backup.' ('.round(filesize($backup) / 1048576, 2).' MB)');
        } else {
            $this->warn('SQLite deyil — ehtiyat nüsxəni əvvəlcədən özünüz götürün.');
        }

        $userIds = DB::table('users')->whereIn('company_id', $ids)->pluck('id')->all();
        $emails = DB::table('users')->whereIn('company_id', $ids)->pluck('email')->all();
        $deleted = [];

        if ($sqlite) {
            DB::statement('PRAGMA foreign_keys = OFF');
        } else {
            Schema::disableForeignKeyConstraints();
        }
        try {
            DB::transaction(function () use ($ids, $scoped, $tables, $userIds, $emails, $sqlite, &$deleted) {
                foreach ($scoped as $t) {
                    $n = DB::table($t)->whereIn('company_id', $ids)->delete();
                    if ($n) {
                        $deleted[$t] = $n;
                    }
                }
                if (Schema::hasTable('sessions')) {
                    $deleted['sessions'] = DB::table('sessions')->whereIn('user_id', $userIds)->delete();
                }
                if (Schema::hasTable('password_reset_tokens')) {
                    DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
                }
                $deleted['companies'] = DB::table('companies')->whereIn('id', $ids)->delete();

                // Child rows without a company column (invoice lines, contacts, checklist…) whose parent is gone.
                if ($sqlite) {
                    do {
                        $round = 0;
                        foreach ($tables as $t) {
                            foreach (DB::select("PRAGMA foreign_key_list(\"{$t}\")") as $fk) {
                                $n = DB::table($t)->whereNotNull($fk->from)
                                    ->whereNotIn($fk->from, DB::table($fk->table)->select($fk->to ?? 'id'))->delete();
                                if ($n) {
                                    $deleted[$t] = ($deleted[$t] ?? 0) + $n;
                                    $round += $n;
                                }
                            }
                        }
                    } while ($round > 0);
                }
            });
        } finally {
            if ($sqlite) {
                DB::statement('PRAGMA foreign_keys = ON');
            } else {
                Schema::enableForeignKeyConstraints();
            }
        }

        foreach ($ids as $id) {
            foreach (['attachments', 'imports', 'logos'] as $dir) {
                Storage::disk('local')->deleteDirectory($dir.'/'.$id);
            }
        }
        Cache::flush();

        ksort($deleted);
        foreach ($deleted as $t => $n) {
            $this->line(str_pad($t, 28).$n);
        }
        if ($sqlite) {
            $broken = DB::select('PRAGMA foreign_key_check');
            if ($broken) {
                $this->error('Xarici açar yoxlaması: '.count($broken).' uyğunsuz sətir.');

                return self::FAILURE;
            }
            $this->info('Xarici açar yoxlaması: təmiz.');
        }
        $this->info(count($ids).' demo şirkət silindi.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_values(array_filter(array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::getTableListing(null, false)),
            fn ($t) => ! in_array($t, ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sqlite_sequence'], true)));
    }
}
