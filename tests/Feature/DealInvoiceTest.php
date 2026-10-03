<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Project;
use App\Support\Invoices\SupplierInvoiceSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Tədarük: project lot with buyer + seller contracts and the seller's proforma imported from Excel. */
class DealInvoiceTest extends TestCase
{
    use RefreshDatabase;

    /** Rows from the company's own working sheet (header on row 1, computed columns, totals row). */
    private const ROWS = [
        ['221619', 1, 'TD-Weiss Migrastar Gr.1/S/IPA', '32151900', 3200, 'kg', 4.89, 15648, 832, 5.15],
        ['221619', 2, 'Supra EB Cyan Folie FCM', '32151900', 3200, 'kg', 11.08, 35456, 1884, 11.67],
        ['221619', 9, 'HERMA PE weiss tc (852) 62Gpt / 517', '39199080', 800, 'kg', 6.67, 5336, 284, 7.02],
    ];

    private function world(): array
    {
        $admin = $this->makeCompany();
        $d = $this->inTenant($admin, function () {
            $buyer = Counterparty::create(['type' => 'customer', 'entity_type' => 'legal', 'name' => 'Alıcı OOO', 'country' => 'Rusiya']);
            $seller = Counterparty::create(['type' => 'supplier', 'entity_type' => 'legal', 'name' => 'Satıcı GmbH', 'country' => 'Almaniya']);
            $mk = fn ($cp, $kind, $n) => Contract::create(['number' => $n, 'contract_date' => today(), 'counterparty_id' => $cp->id, 'kind' => $kind,
                'subject' => 'Boya', 'amount' => 100000, 'currency' => 'EUR', 'cbar_rate' => 1.9, 'amount_azn' => 190000, 'status' => 'active']);
            $sale = $mk($buyer, 'sale', 'S-1');
            $purchase = $mk($seller, 'purchase', 'P-1');
            $project = Project::create(['code' => 'PRJ-1', 'name' => 'Boya tədarükü', 'status' => 'active', 'priority' => 'medium', 'currency' => 'EUR',
                'counterparty_id' => $buyer->id, 'sale_contract_id' => $sale->id, 'supplier_id' => $seller->id, 'purchase_contract_id' => $purchase->id]);

            return compact('buyer', 'seller', 'sale', 'purchase', 'project');
        });

        return [$admin, $d];
    }

    private function companySheet(array $rows, bool $withTotals = true): UploadedFile
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet();
        $s->fromArray(['Proforma N', 'N', 'Description', 'HS Code', 'Quantity', 'UOM', 'Unit Price', 'Total/EUR', 'Logistics', 'UNIT PRICE+LOG'], null, 'A1');
        $s->fromArray($rows, null, 'A2', true);
        if ($withTotals) {
            $s->setCellValue('H'.(count($rows) + 2), array_sum(array_column($rows, 7)));
        }
        $path = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'proforma.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function deal($admin, array $d): Deal
    {
        return $this->inTenant($admin, fn () => Deal::create(['project_id' => $d['project']->id, 'code' => 'TD-1', 'title' => 'Partiya 1', 'deal_date' => today(),
            'currency' => 'EUR', 'status' => 'draft', 'counterparty_id' => $d['buyer']->id, 'sale_contract_id' => $d['sale']->id,
            'supplier_id' => $d['seller']->id, 'purchase_contract_id' => $d['purchase']->id]));
    }

    public function test_deal_form_prefills_from_project_and_stores_contract_pdf(): void
    {
        Storage::fake('local');
        [$admin, $d] = $this->world();
        $this->actingAs($admin);

        $this->get(route('deals.create', $d['project']))->assertOk()
            ->assertSee('Məhsulu alan tərəf')->assertSee('Məhsulu satan tərəf')->assertSee('S-1 · Boya')->assertSee('P-1 · Boya');

        $this->post(route('deals.store', $d['project']), [
            'title' => 'Oktyabr partiyası', 'deal_date' => today()->toDateString(), 'currency' => 'EUR', 'status' => 'active',
            'counterparty_id' => $d['buyer']->id, 'sale_contract_id' => $d['sale']->id,
            'purchase_contract_id' => $d['purchase']->id, // seller comes from the contract
            'purchase_contract_file' => UploadedFile::fake()->create('alis-imzali.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $deal = $this->inTenant($admin, fn () => Deal::firstOrFail());
        $this->assertMatchesRegularExpression('/^TD-\d{4}-0001$/', $deal->code);
        $this->assertSame($d['seller']->id, $deal->supplier_id);
        $this->assertSame(1, $this->inTenant($admin, fn () => $d['purchase']->attachments()->count()), 'signed PDF kept on the contract');
        $this->get(route('deals.show', $deal))->assertOk()->assertSee('alis-imzali.pdf')->assertSee('Satıcının fakturaları');
        $this->get(route('projects.show', [$d['project'], 'tab' => 'deals']))->assertOk()->assertSee('Oktyabr partiyası');
    }

    public function test_deal_contract_slots_follow_the_side_rules(): void
    {
        [$admin, $d] = $this->world();
        $this->actingAs($admin)->post(route('deals.store', $d['project']), [
            'title' => 'X', 'deal_date' => today()->toDateString(), 'currency' => 'EUR', 'status' => 'draft',
            'sale_contract_id' => $d['purchase']->id,
        ])->assertSessionHasErrors('sale_contract_id');
    }

    public function test_template_marks_seller_columns_and_locks_the_rest(): void
    {
        [$admin] = $this->world();
        $res = $this->actingAs($admin)->get(route('invoices.template'));
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $res->streamedContent());

        $sheet = IOFactory::load($path)->getSheetByName('Faktura');
        $this->assertSame('Proforma N', $sheet->getCell('A5')->getValue());
        $this->assertSame('Total/EUR', $sheet->getCell('H5')->getValue());
        $this->assertSame('Logistics', $sheet->getCell('I5')->getValue());
        $this->assertTrue($sheet->getProtection()->getSheet());
        $this->assertSame('unprotected', $sheet->getStyle('H6')->getProtection()->getLocked(), 'Total/EUR is fillable');
        $this->assertNotSame('unprotected', $sheet->getStyle('I6')->getProtection()->getLocked(), 'Logistics is locked');
        $this->assertStringContainsString('YALNIZ', (string) $sheet->getCell('A2')->getValue());

        // The empty template itself parses as "no data", not as a crash.
        $this->assertNotEmpty(SupplierInvoiceSheet::parse($path)['errors']);
    }

    public function test_import_creates_invoice_bound_to_purchase_contract(): void
    {
        Storage::fake('local');
        $this->fakeCbar();
        [$admin, $d] = $this->world();
        $deal = $this->deal($admin, $d);
        $date = now()->subDays(2)->toDateString();

        $this->actingAs($admin)->post(route('invoices.import', $deal), ['file' => $this->companySheet(self::ROWS), 'invoice_date' => $date])
            ->assertRedirect()->assertSessionHasNoErrors();

        $inv = $this->inTenant($admin, fn () => Invoice::with('items')->firstOrFail());
        $this->assertSame('supplier', $inv->type);
        $this->assertSame('221619', $inv->number);
        $this->assertSame($d['purchase']->id, $inv->contract_id);
        $this->assertSame($d['seller']->id, $inv->counterparty_id);
        $this->assertSame('EUR', $inv->currency);
        $this->assertSame(56440.0, (float) $inv->total);
        $this->assertEqualsWithDelta(round(56440 * (float) $inv->cbar_rate, 2), (float) $inv->total_azn, 0.001);
        $this->assertCount(3, $inv->items);
        $this->assertSame([1, 2, 9], $inv->items->pluck('line_no')->all());
        $this->assertSame('32151900', $inv->items[0]->hs_code);
        $this->assertSame('invoiced', $deal->fresh()->status);

        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('TD-Weiss Migrastar')->assertSee('UNIT PRICE CCL EUR');
        $this->get(route('invoices.export', [$inv, 'format' => 'xlsx']))->assertOk();
        $this->get(route('invoices.export', [$inv, 'format' => 'pdf']))->assertOk();

        // The same proforma again is refused.
        $this->post(route('invoices.import', $deal), ['file' => $this->companySheet(self::ROWS), 'invoice_date' => $date])->assertSessionHas('error');
        $this->assertSame(1, $this->inTenant($admin, fn () => Invoice::count()));
    }

    public function test_bad_rows_block_the_whole_import(): void
    {
        $this->fakeCbar();
        [$admin, $d] = $this->world();
        $deal = $this->deal($admin, $d);
        $rows = self::ROWS;
        $rows[1][7] = 99999;          // Total ≠ Quantity × Unit Price
        $rows[2][4] = '';             // missing quantity

        $this->actingAs($admin)->post(route('invoices.import', $deal), ['file' => $this->companySheet($rows, false), 'invoice_date' => today()->toDateString()])
            ->assertSessionHas('import_errors', fn ($e) => count($e) === 2 && str_contains($e[3], 'uyğun gəlmir') && str_contains($e[4], 'Quantity'));
        $this->assertSame(0, $this->inTenant($admin, fn () => Invoice::count()));
    }

    public function test_several_proformas_in_one_file_become_separate_invoices(): void
    {
        $this->fakeCbar();
        [$admin, $d] = $this->world();
        $deal = $this->deal($admin, $d);
        $rows = self::ROWS;
        $rows[2][0] = '221700';

        $this->actingAs($admin)->post(route('invoices.import', $deal), ['file' => $this->companySheet($rows), 'invoice_date' => now()->subDay()->toDateString()])
            ->assertRedirect(route('deals.show', $deal));
        $this->assertSame(['221619', '221700'], $this->inTenant($admin, fn () => Invoice::orderBy('number')->pluck('number')->all()));
    }

    public function test_import_requires_the_sellers_contract(): void
    {
        [$admin, $d] = $this->world();
        $deal = $this->inTenant($admin, fn () => Deal::create(['project_id' => $d['project']->id, 'code' => 'TD-2', 'title' => 'Boş', 'deal_date' => today(), 'currency' => 'EUR', 'status' => 'draft']));
        $this->actingAs($admin)->post(route('invoices.import', $deal), ['file' => $this->companySheet(self::ROWS), 'invoice_date' => today()->toDateString()])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'alış müqaviləsinə'));
    }
}
