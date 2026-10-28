<?php

namespace Tests\Feature;

use App\Models\CurrencyRate;
use App\Services\Cbar\CurrencyRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** CBAR kursları importu: a bulletin XML, an Excel list, a typed rate — never over CBAR's own, replaced by it later. */
class CurrencyImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_rates_come_in_three_ways_and_cbar_wins(): void
    {
        $this->fakeCbar(['11.03.2025', '12.03.2025', '13.03.2025']);
        $admin = $this->makeCompany();
        $this->actingAs($admin);
        $this->get(route('currency.import.index'))->assertOk()->assertSee('CBAR kursları importu')->assertSee('Şablon');
        $this->assertStringContainsString('spreadsheetml', $this->get(route('currency.import.template'))->assertOk()->headers->get('content-type'));

        // 1. the bulletin downloaded from cbar.az
        $xml = preg_replace('/Date="[^"]+"/', 'Date="11.03.2025"', file_get_contents(base_path('tests/Fixtures/cbar-sample.xml')), 1);
        $path = tempnam(sys_get_temp_dir(), 'cb').'.xml';
        file_put_contents($path, $xml);
        $junk = tempnam(sys_get_temp_dir(), 'cb').'.xml';
        file_put_contents($junk, '<nope/>');
        $this->post(route('currency.import.xml'), ['files' => [new UploadedFile($path, '11.03.2025.xml', 'text/xml', null, true), new UploadedFile($junk, 'x.xml', 'text/xml', null, true)]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '11.03.2025') && str_contains($m, 'x.xml'));
        $this->assertGreaterThan(10, CurrencyRate::where('rate_date', '2025-03-11')->where('source', 'CBAR fayl')->count());
        $this->assertNotNull(app(CurrencyRates::class)->tryRate('USD', '2025-03-11'));

        // 2. the Excel list: RUB per 100, as CBAR writes it
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Tarix', 'Valyuta', 'Məzənnə', 'Nominal'], ['12.03.2025', 'EUR', 1.8436, 1], ['12.03.2025', 'RUB', '1,9551', 100], ['xx', 'EUR', 1, 1], ['12.03.2099', 'EUR', 1, 1]]);
        $x = tempnam(sys_get_temp_dir(), 'cb').'.xlsx';
        (new Xlsx($book))->save($x);
        $this->post(route('currency.import.excel'), ['file' => new UploadedFile($x, 'kurslar.xlsx', null, null, true)])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Əlavə olundu: 2') && str_contains($m, '4-ci sətir') && str_contains($m, 'gələcək tarix'));
        $this->assertSame(0.019551, app(CurrencyRates::class)->rate('RUB', '2025-03-12'));
        $this->assertSame(1.8436, app(CurrencyRates::class)->rate('EUR', '2025-03-12'));

        // 3. typed in; a second one corrects it
        $this->post(route('currency.import.store'), ['rate_date' => '2025-03-13', 'currency_code' => 'eur', 'rate' => '1,8461', 'nominal' => '1'])->assertSessionHas('success');
        $this->post(route('currency.import.store'), ['rate_date' => '2025-03-13', 'currency_code' => 'EUR', 'rate' => '1,8462', 'nominal' => '1'])->assertSessionHas('success');
        $this->assertSame(1.8462, app(CurrencyRates::class)->rate('EUR', '2025-03-13'));
        $this->assertSame($admin->id, CurrencyRate::where('rate_date', '2025-03-13')->value('created_by'));

        // CBAR's own row is never overwritten
        CurrencyRate::insert(['currency_code' => 'USD', 'rate_date' => '2025-03-14', 'bulletin_date' => '2025-03-14', 'rate' => 1.7, 'nominal' => 1, 'value' => 1.7, 'source' => 'CBAR', 'created_at' => now(), 'updated_at' => now()]);   // as store() writes CBAR's own
        $this->post(route('currency.import.store'), ['rate_date' => '2025-03-14', 'currency_code' => 'USD', 'rate' => '9', 'nominal' => '1'])->assertSessionHas('error');
        $this->assertSame('1.70000000', (string) CurrencyRate::where('rate_date', '2025-03-14')->value('rate'));
        $this->post(route('currency.import.store'), ['rate_date' => '2099-01-01', 'currency_code' => 'EUR', 'rate' => '1'])->assertSessionHasErrors('rate_date');

        // the list shows them, a hand-made one can be taken back, CBAR's own cannot
        $this->get(route('currency.import.index'))->assertOk()->assertSee('CBAR fayl')->assertSee('Excel')->assertSee('Əl ilə');
        $this->delete(route('currency.import.destroy', CurrencyRate::where('rate_date', '2025-03-13')->first()))->assertSessionHas('success');
        $this->delete(route('currency.import.destroy', CurrencyRate::where('rate_date', '2025-03-14')->first()))->assertForbidden();

        // without the permission: no page
        $employee = $this->makeUser($admin->company, 'employee', 'isci@test.az');
        $this->actingAs($employee)->get(route('currency.import.index'))->assertForbidden();
    }
}
