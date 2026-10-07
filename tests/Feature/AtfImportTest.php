<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Counterparty;
use App\Models\CurrencyExchange;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LogisticsAct;
use App\Models\LogisticsPayment;
use App\Models\Project;
use App\Models\SupplierPayment;
use App\Support\Reports\TradeReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Toplu Trade importu: an ATF workbook → Trades worked through end to end, row by row. */
class AtfImportTest extends TestCase
{
    use RefreshDatabase;

    /** A workbook in the ATF layout (sheet «ATF», headers on row 2, data from row 3). */
    private function workbook(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('ATF');
        foreach (['B' => 'Номер инвойса Ellis', 'H' => 'Цена инвойса AXIOS', 'AX' => 'ODENIS LOGISTIKA', 'BI' => 'AKT TARIX'] as $col => $h) {
            $sheet->setCellValue($col.'2', $h);
        }
        foreach ($rows as $i => $row) {
            foreach ($row as $col => $v) {
                $sheet->setCellValue($col.($i + 3), $v);
            }
        }
        $sheet->setCellValue('J3', '=D3*0.25%');   // a formula cell, as in the sheet
        $path = tempnam(sys_get_temp_dir(), 'atf').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'ATF (kurslarla).xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_rows_become_trades_with_every_operation(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        [$ellis, $axios, $carrier, $eur, $rub, $azn] = $this->inTenant($admin, fn () => [
            Counterparty::create(['type' => 'supplier', 'entity_type' => 'legal', 'name' => 'Ellis GmbH', 'country' => 'Almaniya']),
            Counterparty::create(['type' => 'customer', 'entity_type' => 'legal', 'name' => 'Axios LLC', 'country' => 'Russia']),
            Counterparty::create(['type' => 'logistics', 'entity_type' => 'legal', 'name' => 'Ritloga', 'country' => 'Poland']),
            BankAccount::create(['name' => 'EURO', 'bank_name' => 'Turan', 'currency' => 'EUR', 'is_active' => true]),
            BankAccount::create(['name' => 'RUBL', 'bank_name' => 'Turan', 'currency' => 'RUB', 'is_active' => true]),
            BankAccount::create(['name' => 'AZN', 'bank_name' => 'Turan', 'currency' => 'AZN', 'is_active' => true]),
        ]);
        $this->actingAs($admin);
        $this->get(route('imports.atf.create'))->assertOk()->assertSee('Toplu Trade importu');

        // the template keeps the ATF sheet's columns (A…BT) and headers, so rows can be pasted over
        $this->get(route('imports.atf.create'))->assertSee(route('imports.atf.template'), false);
        $tpl = $this->get(route('imports.atf.template'))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $tpl->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetByName('ATF');
        $this->assertSame(['Номер инвойса Ellis', 'Цена инвойса AXIOS', 'ODENIS LOGISTIKA', 'AKT TARIX', 'XALIS MENFEET'],
            [$sheet->getCell('B2')->getValue(), $sheet->getCell('H2')->getValue(), $sheet->getCell('AX2')->getValue(), $sheet->getCell('BI2')->getValue(), $sheet->getCell('BT2')->getValue()]);
        $this->assertSame(['rows' => [], 'errors' => ['Cədvəldə Trade sətri tapılmadı.']], \App\Imports\Atf\AtfSheet::parse($path));

        $file = $this->workbook([
            ['A' => 1, 'B' => '916161494', 'C' => '27.12.2024', 'D' => 73644.8, 'E' => '07.01.2025', 'F' => 'ATFAXI-1204', 'G' => '27.12.2024', 'H' => 9898036.68, 'I' => 9300,
                'K' => '27.12.2024', 'N' => 1.79, 'O' => 0.0165, 'T' => '29.12.2024', 'X' => '07.01.2025', 'AF' => 1.779, 'AG' => 0.0159, 'AO' => 9898036.68, 'AP' => 73829,
                'AX' => 979732.68, 'AY' => '10.02.2025', 'BA' => 0.017526, 'BB' => 42.93, 'BF' => 'VAM25006583_179', 'BG' => '28.01.2025', 'BH' => 'VAM25006583', 'BI' => '28.01.2025'],
            ['A' => 54, 'B' => '02/EXP/2026', 'C' => '19.06.2026', 'D' => 68114, 'F' => 'ATFAXI-0601', 'G' => '30.06.2026', 'H' => 7250948.08, 'I' => 7900,
                'K' => '30.06.2026', 'N' => 1.95, 'O' => 0.0214, 'T' => '01.07.2026', 'X' => '01.07.2026', 'AF' => 1.944, 'AG' => 0.0215, 'AO' => 6528048.08, 'AP' => 67961,
                'AX' => 722900, 'AY' => '01.07.2026', 'BA' => 0.021697, 'BF' => 'KIK26011981_1358', 'BG' => '01.07.2026'],
            ['A' => 55, 'D' => 100],   // no seller invoice number: refused
        ]);
        $setup = ['supplier_id' => $ellis->id, 'buyer_id' => $axios->id, 'logistics_id' => $carrier->id, 'eur_account' => $eur->id, 'rub_account' => $rub->id, 'azn_account' => $azn->id];

        $this->post(route('imports.atf.store'), ['eur_account' => $rub->id] + $setup + ['file' => $file, 'project_name' => 'ATF'])->assertSessionHasErrors('eur_account');
        $res = $this->post(route('imports.atf.store'), $setup + ['file' => $this->workbook([]), 'project_name' => 'ATF']);
        $res->assertSessionHas('error');   // no Trade rows

        $res = $this->post(route('imports.atf.store'), $setup + ['file' => $file, 'project_name' => 'ATF 2025–2026']);
        $res->assertRedirect();
        $token = basename($res->headers->get('Location'));
        $this->get(route('imports.atf.show', $token))->assertOk()->assertSee('916161494')->assertSee('02/EXP/2026')
            ->assertSee('Satıcıya ödəniş tarixi yoxdur — əməliyyat tarixi (X) götürülür.')->assertSee('Akt hələ yoxdur');

        $this->postJson(route('imports.atf.row', [$token, 0]))->assertJsonPath('ok', true)->assertJsonCount(7, 'steps');
        $this->postJson(route('imports.atf.row', [$token, 1]))->assertJsonPath('ok', true);
        $this->postJson(route('imports.atf.row', [$token, 2]))->assertJsonPath('ok', false);
        $this->postJson(route('imports.atf.row', [$token, 0]))->assertJsonPath('skipped', true);   // never twice

        $this->inTenant($admin, function () use ($eur, $azn) {
            $project = Project::where('name', 'ATF 2025–2026')->firstOrFail();
            $this->assertSame(2, Deal::where('project_id', $project->id)->count());
            $inv = Invoice::where('number', '916161494')->firstOrFail();
            $this->assertSame(['manual', '73644.80', '9898036.68', 'ATFAXI-1204', '1.79000000', '0.01650000', 'forecast', '9300.00'],
                [$inv->entry_mode, $inv->total, $inv->final_amount, $inv->sale_number, $inv->fx_base_azn, $inv->fx_target_azn, $inv->logistics_mode, $inv->logistics_amount]);
            $deal = $inv->deal;
            $this->assertSame('completed', $deal->status);
            $this->assertSame(9898036.68, (float) BankTransaction::where('deal_id', $deal->id)->where('direction', 'in')->where('kind', 'regular')->sum('amount'));
            $this->assertSame([['sell', 'RUB', '9898036.68'], ['buy', 'EUR', '73829.00']],
                CurrencyExchange::where('deal_id', $deal->id)->orderBy('id')->get()->map(fn ($x) => [$x->direction, $x->currency, $x->amount])->all());
            $sp = SupplierPayment::where('deal_id', $deal->id)->firstOrFail();
            $this->assertSame(['2025-01-07', '73644.80', '184.11'], [$sp->payment_date->toDateString(), $sp->amount, $sp->fee_amount], 'the fee from the formula J = D × 0.25%');
            $act = LogisticsAct::where('deal_id', $deal->id)->firstOrFail();
            $this->assertSame(['VAM25006583_179', 'VAM25006583', '2025-01-28', 'RUB', '979732.68'], [$act->logistics_invoice_number, $act->act_number, $act->act_date->toDateString(), $act->currency, $act->amount]);
            $lp = LogisticsPayment::where('logistics_act_id', $act->id)->firstOrFail();
            $this->assertSame([979732.68, 42.93, $azn->id], [(float) $lp->amount, (float) $lp->fee_account_amount, (int) $lp->fee_account_id], 'fee 42.93 AZN at the bank rate 0.017526');
            $this->assertSame(1, Expense::where('deal_id', $deal->id)->where('currency', 'AZN')->count());

            // the last row: no act yet, seller paid on the operation day, fees by the bank's rule
            $late = Invoice::where('number', '02/EXP/2026')->firstOrFail()->deal;
            $this->assertSame('2026-07-01', SupplierPayment::where('deal_id', $late->id)->firstOrFail()->payment_date->toDateString());
            $this->assertNull(LogisticsAct::where('deal_id', $late->id)->firstOrFail()->act_date);

            // and the report picks them up
            $rows = app(TradeReport::class)->rows(Deal::where('project_id', $project->id)->get());
            $this->assertSame(['916161494', '02/EXP/2026'], array_column($rows, 'seller_no'));
            $this->assertSame('ATFAXI-1204', $rows[0]['buyer_no']);
            $this->assertNotNull($rows[0]['AQ']);
        });
    }
}
