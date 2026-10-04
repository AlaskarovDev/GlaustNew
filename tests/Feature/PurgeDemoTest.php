<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** glaust:purge-demo removes the *.demo companies with every row they own and nothing else. */
class PurgeDemoTest extends TestCase
{
    use DatabaseMigrations; // VACUUM INTO and PRAGMA foreign_keys cannot run inside the RefreshDatabase transaction

    private function withData(User $admin): void
    {
        $this->inTenant($admin, function () {
            $c = Counterparty::create(['type' => 'supplier', 'entity_type' => 'legal', 'name' => 'X', 'country' => 'AZ']);
            $c->contacts()->create(['name' => 'Kontakt']);
            $p = Project::create(['code' => 'P', 'name' => 'P', 'status' => 'active', 'priority' => 'medium', 'currency' => 'EUR']);
            $d = Deal::create(['project_id' => $p->id, 'code' => 'TR-1', 'title' => 'T', 'deal_date' => today(), 'currency' => 'EUR', 'status' => 'draft']);
            $inv = $d->invoices()->create(['project_id' => $p->id, 'type' => 'supplier', 'number' => '1', 'invoice_date' => today(), 'currency' => 'EUR', 'total' => 10, 'status' => 'draft']);
            $inv->items()->create(['line_no' => 1, 'description' => 'A', 'quantity' => 1, 'unit_price' => 10, 'total' => 10]);
        });
    }

    public function test_dry_run_then_purge(): void
    {
        Storage::fake('local');
        $demo = $this->makeCompany('Demo MMC', 'admin@demo-mmc.demo');
        $real = $this->makeCompany('Real MMC', 'admin@real.az');
        $this->withData($demo);
        $this->withData($real);

        $this->artisan('glaust:purge-demo')->expectsOutputToContain('Demo MMC')->expectsOutputToContain('heç nə silinmədi')->assertSuccessful();
        $this->assertSame(2, Company::count());

        $this->artisan('glaust:purge-demo', ['--force' => true])->expectsOutputToContain('Xarici açar yoxlaması: təmiz')->assertSuccessful();
        $this->assertNull(Company::find($demo->company_id));
        $this->assertSame(0, User::where('company_id', $demo->company_id)->count());
        $this->assertNotNull(Company::find($real->company_id));
        $this->assertSame(1, InvoiceItem::count(), 'only the real company\'s invoice line is left');
        $this->assertSame(1, \DB::table('counterparty_contacts')->count());
        $this->assertSame(1, $this->inTenant($real, fn () => Invoice::count()));
        $this->assertCount(1, Storage::disk('local')->files('backups'), 'backup written first');

        $this->artisan('glaust:purge-demo')->expectsOutputToContain('Demo şirkəti yoxdur')->assertSuccessful();
    }
}
