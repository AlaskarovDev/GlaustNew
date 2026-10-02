<?php

namespace App\Jobs;

use App\Imports\ImportRunner;
use App\Models\Company;
use App\Models\Import;
use App\Support\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Large imports run on the queue (worked by the scheduler's queue:work on shared hosting). */
class ProcessImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $importId, public int $companyId) {}

    public function handle(Tenant $tenant, ImportRunner $runner): void
    {
        $company = Company::findOrFail($this->companyId);
        $tenant->runAs($company, function () use ($runner) {
            $import = Import::findOrFail($this->importId);
            $runner->run($import);
        });
    }

    public function failed(\Throwable $e): void
    {
        $company = Company::find($this->companyId);
        if ($company) {
            app(Tenant::class)->runAs($company, fn () => Import::whereKey($this->importId)->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 500)]));
        }
    }
}
