<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankAudit;
use App\Models\BankTransaction;
use App\Services\BankLedger;
use App\Support\BankAudit\Reconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Bank hesabları auditi: a statement in the bank's layout against the system, every kind of difference, fixed to match. */
class BankAuditTest extends TestCase
{
    use RefreshDatabase;

    private const IBAN = 'AZ00TEST00000000000000000001';

    /** The bank's «Hesabdan çıxarış» layout; $lines oldest first as [date, amount, text]. */
    private function statement(array $lines, float $opening = 0): string
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet();
        $s->fromArray([
            [null], ['Hesabdan çıxarış / Account statement'], ['Çıxarışın tarixi / Statement date:', null, null, null, '06-10-2026'], [null],
            ["Müştərinin Adı / Client's Name", null, null, null, 'TEST MMC'], [null],
            ['Hesab adı', null, null, 'TEST MMC', 'Hesab nömrəsi / IBAN', null, self::IBAN],
            ['Hesabın valyutası / Account Currency', null, null, 'AZN', 'VÖEN', null, '1234567890'],
            ['İlk əməliyyat tarixi', null, null, '2025-01-01', 'Son əməliyyat tarixi', null, '2025-01-31'],
            ['Günün əvvəlinə qalıq', null, null, $opening, 'Günün sonuna qalıq', null, $opening + array_sum(array_column($lines, 1))],
            [null], [null],
            ['№', 'Tarix', 'Hesab nömrəsi', 'Məbləğ', 'Məbləğin AZN dəyəri', 'Qalıq', 'Qalığın AZN dəyəri', 'Valyuta', 'Təyinat'],
        ]);
        $rows = [];
        $bal = $opening;
        foreach ($lines as [$date, $amount, $text]) {
            $bal += $amount;
            $rows[] = [null, date('d-m-Y', strtotime($date)), self::IBAN, $amount, $amount, round($bal, 2), round($bal, 2), 'AZN', $text];
        }
        $rows = array_reverse($rows);   // newest first, as the bank prints it
        foreach ($rows as $i => &$r) {
            $r[0] = $i;
        }
        $s->fromArray([...$rows, [null, null, 'Cəmi', array_sum(array_column($lines, 1))]], null, 'A14');
        $path = tempnam(sys_get_temp_dir(), 'stm').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    public function test_statement_compared_and_fixed_to_match_the_bank(): void
    {
        $admin = $this->makeCompany();
        $account = $this->inTenant($admin, function () {
            $a = BankAccount::create(['name' => 'Turan AZN', 'bank_name' => 'Turan Bank', 'currency' => 'AZN', 'opening_balance' => 0, 'is_active' => true]);
            $l = app(BankLedger::class);
            foreach ([['in', '2025-01-05', 1000], ['out', '2025-01-06', 300], ['out', '2025-01-06', 200], ['in', '2025-01-07', 980],
                ['out', '2025-01-12', 150], ['out', '2025-01-15', 99.5], ['out', '2025-01-20', 70]] as [$dir, $date, $amount]) {
                $l->record($a, ['direction' => $dir, 'transaction_date' => $date, 'amount' => $amount, 'purpose' => 'sistem']);
            }

            return $a;
        });
        $file = $this->statement([
            ['2025-01-05', 1000, 'Daxilolma'],
            ['2025-01-06', -500, 'Ödəniş (sistemdə iki hissə)'],
            ['2025-01-07', 1000, 'Internet Banking, Satış əməliyyatı'],
            ['2025-01-07', -20, '(MultiCurrency Auto Transaction)'],
            ['2025-01-10', -150, 'Ödəniş — sistemdə 12.01'],
            ['2025-01-15', -100, 'Ödəniş — sistemdə 99,50'],
            ['2025-01-25', -10, 'KÖÇ KOM'],
        ]);

        $this->actingAs($admin);
        $this->get(route('bank.audits.index'))->assertOk()->assertSee('Bank hesabları auditi');
        // no account has the IBAN yet: the user picks it, the account keeps the IBAN
        $this->post(route('bank.audits.store'), ['file' => new UploadedFile($file, 'turan-azn.xlsx', null, null, true)])->assertSessionHasErrors('bank_account_id');
        $this->post(route('bank.audits.store'), ['file' => new UploadedFile($file, 'turan-azn.xlsx', null, null, true), 'bank_account_id' => $account->id])->assertRedirect();
        $audit = $this->inTenant($admin, fn () => BankAudit::firstOrFail());
        $this->assertSame(self::IBAN, $account->fresh()->iban);
        $this->assertSame([7, '2025-01-01', '2025-01-31', 1220.0], [$audit->lines()->count(), $audit->period_from->toDateString(), $audit->period_to->toDateString(), (float) $audit->closing]);

        $r = $this->inTenant($admin, fn () => (new Reconciler($audit))->run());
        $this->assertEquals(['ok' => 1, 'split' => 1, 'joined' => 1, 'date' => 1, 'amount' => 1, 'missing_sys' => 1, 'missing_bank' => 1], $r['counts']);
        $this->assertSame(['bank' => 1220.0, 'system' => 1160.5], array_intersect_key($r['balances']['closing'], ['bank' => 1, 'system' => 1]));
        $this->assertSame('2025-01-10', $r['balances']['split']['date'], 'the first day the balances part ways');

        $this->get(route('bank.audits.show', $audit))->assertOk()
            ->assertSee('Sistemdə yoxdur')->assertSee('Bankda yoxdur')->assertSee('Tarix fərqlidir')->assertSee('Məbləğ fərqlidir')
            ->assertSee('Sistemə əlavə et')->assertSee('Tarixi 10.01.2025 et')->assertSee('Sistemdən sil');
        $this->get(route('bank.audits.show', [$audit, 'show' => 'ok']))->assertOk()->assertSee('Uyğun — sistemdə bölünüb')->assertSee('Uyğun — bankda bir neçə sətir');

        // every «Düzəlt» makes the system what the statement shows
        foreach ($r['rows'] as $row) {
            if ($row['fix']) {
                $this->post(route('bank.audits.fix', $audit), $row['fix'])->assertSessionHas('success');
            }
        }
        $after = $this->inTenant($admin, fn () => (new Reconciler($audit))->run());
        $this->assertSame([], array_intersect_key($after['counts'], array_flip(['date', 'amount', 'missing_sys', 'missing_bank'])));
        $this->assertSame(1220.0, $after['balances']['closing']['system']);
        $this->assertNull($after['balances']['split']);
        $this->inTenant($admin, function () {
            $this->assertSame('2025-01-10', BankTransaction::where('amount', 150)->first()->transaction_date->toDateString());
            $this->assertSame('KÖÇ KOM', BankTransaction::where('amount', 10)->first()->purpose);
        });
        $this->get(route('bank.audits.show', $audit))->assertOk()->assertSee('Sistem bank çıxarışı ilə tam uyğundur.');

        // a statement that is not one
        $bad = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        (new Xlsx(new Spreadsheet))->save($bad);
        $this->post(route('bank.audits.store'), ['file' => new UploadedFile($bad, 'x.xlsx', null, null, true)])->assertSessionHasErrors('file');
        $this->delete(route('bank.audits.destroy', $audit))->assertRedirect(route('bank.audits.index'));
        $this->assertSame(7, $this->inTenant($admin, fn () => BankTransaction::count()), 'deleting the statement leaves the movements');
    }

    /**
     * A payment the bank made in one line on 28.01 (−146 522.16) that the system booked per Trade, one part a day early;
     * the transfer fee charged 293.04 + 6.96 two days later, booked 149.80 + 150.20.
     */
    public function test_a_line_booked_in_parts_on_nearby_days(): void
    {
        $admin = $this->makeCompany();
        $account = $this->inTenant($admin, function () {
            $a = BankAccount::create(['name' => 'Turan AZN', 'bank_name' => 'Turan Bank', 'currency' => 'AZN', 'iban' => self::IBAN, 'opening_balance' => 0, 'is_active' => true]);
            $l = app(BankLedger::class);
            foreach ([['in', '2025-01-02', 200000], ['out', '2025-01-27', 73166.72], ['out', '2025-01-27', 149.80], ['out', '2025-01-28', 73355.44], ['out', '2025-01-28', 150.20]] as [$dir, $date, $amount]) {
                $l->record($a, ['direction' => $dir, 'transaction_date' => $date, 'amount' => $amount, 'purpose' => 'sistem']);
            }

            return $a;
        });
        $file = $this->statement([
            ['2025-01-02', 200000, 'Daxilolma'],
            ['2025-01-28', -146522.16, 'Swift köçürmə — iki faktura'],
            ['2025-01-28', -293.04, 'Swift komissiya'],
            ['2025-01-30', -6.96, 'ƏSKİK TUTULMUŞ KOM'],
        ]);
        $this->actingAs($admin);
        $this->post(route('bank.audits.store'), ['file' => new UploadedFile($file, 'turan.xlsx', null, null, true)])->assertRedirect();   // found by IBAN
        $audit = $this->inTenant($admin, fn () => BankAudit::firstOrFail());

        $r = $this->inTenant($admin, fn () => (new Reconciler($audit))->run());
        $this->assertEquals(['ok' => 1, 'date' => 1, 'regroup' => 1], $r['counts']);
        $row = collect($r['rows'])->firstWhere('status', 'date');
        $this->assertCount(2, $row['txs'], 'both parts of the payment');
        $this->assertSame('date', $row['fix']['action']);
        $this->assertCount(1, $row['fix']['txs'], 'only the part booked a day early moves');
        $this->get(route('bank.audits.show', $audit))->assertOk()->assertSee('Tarixi 28.01.2025 et')->assertSee('Auditi sil');
        $this->get(route('bank.audits.show', [$audit, 'show' => 'ok']))->assertOk()->assertSee('Uyğun — bölgü fərqlidir');

        $this->post(route('bank.audits.fix', $audit), $row['fix'])->assertSessionHas('success');
        $this->inTenant($admin, fn () => $this->assertSame('2025-01-28', BankTransaction::where('amount', 73166.72)->first()->transaction_date->toDateString()));
        $after = $this->inTenant($admin, fn () => (new Reconciler($audit))->run());
        $this->assertEquals(['ok' => 1, 'split' => 1, 'regroup' => 1], $after['counts'], 'now the same day: a split payment');

        // delete the audit and upload the statement again
        $this->delete(route('bank.audits.destroy', $audit))->assertRedirect(route('bank.audits.index'));
        $this->post(route('bank.audits.store'), ['file' => new UploadedFile($file, 'turan.xlsx', null, null, true)])->assertRedirect();
        $this->assertSame(1, $this->inTenant($admin, fn () => BankAudit::count()));
        $this->assertSame($account->id, $this->inTenant($admin, fn () => BankAudit::first()->bank_account_id));
    }

    /**
     * «Birləşdir»: five small bank fees of a day tied by hand to the one fee expense booked for them the next day;
     * «Sistemə əlavə et» of money out as an expense of a category, or on the expense page filled from the line.
     */
    public function test_link_by_hand_and_add_as_an_expense(): void
    {
        $admin = $this->makeCompany();
        [$account, $fee] = $this->inTenant($admin, function () {
            $a = BankAccount::create(['name' => 'Turan AZN', 'bank_name' => 'Turan Bank', 'currency' => 'AZN', 'iban' => self::IBAN, 'opening_balance' => 0, 'is_active' => true]);
            app(BankLedger::class)->record($a, ['direction' => 'in', 'transaction_date' => '2025-02-01', 'amount' => 5000, 'purpose' => 'x']);
            $fee = app(BankLedger::class)->record($a, ['direction' => 'out', 'transaction_date' => '2025-02-28', 'amount' => 9.70, 'purpose' => 'Komissiya']);

            return [$a, $fee];
        });
        $cat = $this->inTenant($admin, fn () => \App\Models\Category::create(['scope' => 'expense', 'name' => 'Əmək haqqı', 'color' => '#64748b']));
        $file = $this->statement([
            ['2025-02-01', 5000, 'Daxilolma'],
            ['2025-02-27', -1.00, 'XOHKS xidmət haqqı'], ['2025-02-27', -1.00, 'XOHKS xidmət haqqı'], ['2025-02-27', -1.00, 'XOHKS xidmət haqqı'],
            ['2025-02-27', -1.60, 'XOHKS xidmət haqqı'], ['2025-02-27', -5.10, 'Internet banking komissiya'],
            ['2025-02-27', -728.00, 'Internet banking, Yanvar Fevral 2025 EH'],
        ]);
        $this->actingAs($admin);
        $this->post(route('bank.audits.store'), ['file' => new UploadedFile($file, 'turan.xlsx', null, null, true)])->assertRedirect();
        $audit = $this->inTenant($admin, fn () => BankAudit::firstOrFail());
        $fees = $this->inTenant($admin, fn () => $audit->lines()->where('amount', '>', -10)->where('amount', '<', 0)->pluck('id')->all());
        $this->assertCount(5, $fees);
        $this->get(route('bank.audits.show', $audit))->assertOk()->assertSee('Birləşdir')->assertSee('Bu sətir sistemə nə kimi əlavə olunsun?')->assertSee('Əmək haqqı');

        // the totals must agree
        $this->post(route('bank.audits.link', $audit), ['lines' => array_slice($fees, 0, 4), 'txs' => [$fee->id]])->assertSessionHas('error');
        $this->post(route('bank.audits.link', $audit), ['lines' => $fees, 'txs' => [$fee->id], 'align' => 1])->assertSessionHas('success');
        $this->inTenant($admin, fn () => $this->assertSame('2025-02-27', $fee->fresh()->transaction_date->toDateString(), 'aligned to the bank day'));
        $r = $this->inTenant($admin, fn () => (new Reconciler($audit))->run());
        $manual = collect($r['rows'])->firstWhere('status', 'manual');
        $this->assertCount(5, $manual['lines']);
        $this->get(route('bank.audits.show', [$audit, 'show' => 'ok']))->assertOk()->assertSee('Əl ilə birləşdirilib')->assertSee('Ayır');

        // the salary: an expense of its category, paid from the account
        $salary = $this->inTenant($admin, fn () => $audit->lines()->where('amount', -728)->value('id'));
        $this->post(route('bank.audits.fix', $audit), ['action' => 'expense', 'line' => $salary])->assertSessionHasErrors('category_id');
        $this->post(route('bank.audits.fix', $audit), ['action' => 'expense', 'line' => $salary, 'category_id' => $cat->id])->assertSessionHas('success');
        $this->inTenant($admin, function () use ($cat, $account) {
            $e = \App\Models\Expense::where('category_id', $cat->id)->firstOrFail();
            $this->assertSame([728.0, '2025-02-27', 'paid', $account->id], [(float) $e->amount, $e->paid_at->toDateString(), $e->status, $e->bank_account_id]);
            $this->assertNotNull($e->bank_transaction_id);
        });
        $after = $this->inTenant($admin, fn () => (new Reconciler($audit))->run());
        $this->assertSame([], array_intersect_key($after['counts'], array_flip(['date', 'amount', 'missing_sys', 'missing_bank'])));

        // «Ayır» unties the group; nothing in the system changes
        $this->delete(route('bank.audits.unlink', [$audit, $manual['group']]))->assertSessionHas('success');
        $this->assertSame(0, $this->inTenant($admin, fn () => \App\Models\BankAuditLink::count()));

        // «Başqa xərc»: the expense page comes filled and leads back
        $back = route('bank.audits.show', $audit);
        $this->get(route('expenses.create', ['date' => '2025-02-27', 'amount' => 5.10, 'bank_account_id' => $account->id, 'description' => 'Internet banking komissiya', 'back' => $back]))
            ->assertOk()->assertSee('2025-02-27')->assertSee('Internet banking komissiya')->assertSee('name="back"', false);
        $this->post(route('expenses.store'), ['expense_date' => '2025-02-27', 'category_id' => $cat->id, 'description' => 'x', 'amount' => '5.10', 'currency' => 'AZN',
            'status' => 'paid', 'payment_method' => 'bank', 'paid_at' => '2025-02-27', 'bank_account_id' => $account->id, 'back' => $back])->assertRedirect($back);
        $this->post(route('expenses.store'), ['expense_date' => '2025-02-27', 'description' => 'x', 'amount' => '1', 'currency' => 'AZN',
            'status' => 'paid', 'payment_method' => 'bank', 'paid_at' => '2025-02-27', 'bank_account_id' => $account->id, 'back' => 'https://evil.example/x'])->assertRedirect(route('expenses.index'));
    }
}
