<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** glaust:report-check prints the report figures of a company and changes nothing. */
class ReportCheckCommandTest extends TestCase
{
    use Concerns\AtfRow3, RefreshDatabase;

    public function test_prints_the_figures(): void
    {
        [$admin] = $this->row3();
        $this->artisan('glaust:report-check')->assertSuccessful();
        $this->artisan('glaust:report-check', ['company' => (string) $admin->company_id, '--from' => '2024-12', '--to' => '2025-02'])
            ->expectsOutputToContain('CASH FLOW')->expectsOutputToContain('916161494')->assertSuccessful();
        $this->artisan('glaust:report-check', ['company' => 'yoxdur-belə'])->assertFailed();
    }
}
