<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Counterparty;
use App\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private function sheet(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'data.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_counterparty_import_maps_columns_imports_valid_rows_and_returns_errors(): void
    {
        Storage::fake('local');
        $admin = $this->makeCompany();
        $file = $this->sheet([
            ['Şirkət', 'VÖEN', 'Tip', 'E-mail', 'Boş sütun'],
            ['Alfa MMC', '1234567891', 'Müştəri', 'info@alfa.az', null],
            ['Beta MMC', '12345', 'Təchizatçı', 'beta@beta.az', null],        // bad VÖEN
            ['Qamma MMC', '', 'hər ikisi', 'yanlış-email', null],             // bad email
            ['Delta MMC', '', 'Satıcı', '', null],
        ]);

        $this->actingAs($admin)->post(route('imports.store'), ['type' => 'counterparties', 'file' => $file])->assertRedirect();
        $import = $this->inTenant($admin, fn () => Import::firstOrFail());
        $this->assertSame(0, $import->mapping['name']);
        $this->assertSame(1, $import->mapping['voen']);
        $this->assertSame(2, $import->mapping['type']);
        $this->assertSame(3, $import->mapping['email']);

        $this->get(route('imports.show', $import))->assertOk()->assertSee('hazırdır')->assertSee('VÖEN');

        $this->post(route('imports.run', $import), ['map' => $import->mapping])->assertRedirect();
        $import->refresh();
        $this->assertSame('done', $import->status);
        $this->assertSame(2, $import->imported_rows);
        $this->assertSame(2, $import->failed_rows);
        $this->assertNotNull($import->error_path);
        Storage::disk('local')->assertExists($import->error_path);
        $this->get(route('imports.errors', $import))->assertOk();

        $names = $this->inTenant($admin, fn () => Counterparty::orderBy('name')->pluck('type', 'name')->all());
        $this->assertSame(['Alfa MMC' => 'customer', 'Delta MMC' => 'supplier'], $names);
    }

    public function test_bank_statement_import_uses_cbar_and_skips_duplicates(): void
    {
        Storage::fake('local');
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $account = $this->inTenant($admin, fn () => BankAccount::create(['name' => 'USD', 'bank_name' => 'B', 'currency' => 'USD', 'opening_balance' => 0]));
        $d = now()->subDays(3)->format('d.m.Y');
        $rows = [['Tarix', 'Mədaxil', 'Məxaric', 'Təyinat', 'Sənəd №'], [$d, '1 000,00', '', 'Satış', '11'], [$d, '', '250,50', 'Komissiya', '12']];

        $this->actingAs($admin)->post(route('imports.store'), ['type' => 'bank_transactions', 'account_id' => $account->id, 'file' => $this->sheet($rows)]);
        $import = $this->inTenant($admin, fn () => Import::latest('id')->firstOrFail());
        $this->post(route('imports.run', $import), ['map' => $import->mapping]);
        $this->assertSame(2, $import->fresh()->imported_rows);

        // The same statement again: nothing new.
        $this->post(route('imports.store'), ['type' => 'bank_transactions', 'account_id' => $account->id, 'file' => $this->sheet($rows)]);
        $again = $this->inTenant($admin, fn () => Import::latest('id')->firstOrFail());
        $this->post(route('imports.run', $again), ['map' => $again->mapping]);
        $this->assertSame(0, $again->fresh()->imported_rows);
        $this->assertSame(2, $again->fresh()->failed_rows);

        $txs = $this->inTenant($admin, fn () => BankTransaction::orderBy('id')->get());
        $this->assertCount(2, $txs);
        $this->assertSame(['in', 'out'], $txs->pluck('direction')->all());
        $this->assertGreaterThan(0, (float) $txs[0]->cbar_rate);
        $this->assertSame(749.5, $this->inTenant($admin, fn () => $account->fresh()->balance()));
    }

    public function test_bank_import_requires_an_account_and_permission(): void
    {
        Storage::fake('local');
        $admin = $this->makeCompany();
        $this->actingAs($admin)->post(route('imports.store'), ['type' => 'bank_transactions', 'file' => $this->sheet([['Tarix'], ['01.01.2026']])])
            ->assertSessionHasErrors('account_id');

        $employee = $this->makeUser($admin->company, 'employee', 'e@test.az');
        $this->actingAs($employee)->get(route('imports.template', 'bank_transactions'))->assertForbidden();
    }
}
