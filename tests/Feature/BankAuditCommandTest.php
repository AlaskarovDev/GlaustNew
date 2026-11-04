<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankAudit;
use App\Services\BankLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** glaust:bank-audit: a statement stored as an audit of the account with its IBAN, what does not match printed. */
class BankAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audits_a_statement_and_prints_what_differs(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $this->inTenant($admin, function () {
            $a = BankAccount::create(['name' => 'AZN Hesabı', 'bank_name' => 'Turan Bank', 'currency' => 'AZN', 'iban' => 'AZ06TURA40160089115400200643', 'opening_balance' => 0, 'is_active' => true]);
            app(BankLedger::class)->record($a, ['direction' => 'in', 'transaction_date' => '2025-03-03', 'amount' => 100]);
            app(BankLedger::class)->record($a, ['direction' => 'out', 'transaction_date' => '2025-03-05', 'amount' => 40]);
        });
        $s = ['iban' => 'AZ06TURA40160089115400200643', 'currency' => 'AZN', 'from' => '2025-03-01', 'to' => '2025-03-31', 'opening' => 0, 'closing' => 75, 'file_name' => 'azn.xlsx',
            'lines' => [['no' => 1, 'date' => '2025-03-03', 'amount' => 100, 'azn' => 100, 'balance' => 100, 'description' => 'Daxilolma'],
                ['no' => 2, 'date' => '2025-03-10', 'amount' => -25, 'azn' => -25, 'balance' => 75, 'description' => 'Komissiya']]];
        $args = ['company' => (string) $admin->company_id, 'payload' => base64_encode(gzencode(json_encode($s)))];

        $this->artisan('glaust:bank-audit', $args)->expectsOutputToContain('missing_sys | B: 2025-03-10 -25.00 Komissiya')->assertSuccessful();
        // without a payload: the audits already uploaded (on the page), the latest of each account
        $this->artisan('glaust:bank-audit', ['company' => (string) $admin->company_id])->expectsOutputToContain('missing_sys | B: 2025-03-10 -25.00 Komissiya')->assertSuccessful();
        $this->artisan('glaust:bank-audit', $args + ['--replace' => true, '--keep' => 0])->expectsOutputToContain('missing_bank | B: — | S: #')->assertSuccessful();
        $this->assertSame(0, $this->inTenant($admin, fn () => BankAudit::count()), '--replace dropped the first, --keep=0 the second');
        $this->artisan('glaust:bank-audit', ['company' => (string) $admin->company_id, 'payload' => base64_encode(gzencode(json_encode(['iban' => 'XX', 'currency' => 'AZN'] + $s)))])->assertFailed();
    }
}
