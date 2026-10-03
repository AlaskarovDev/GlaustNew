<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Xərclər: categories, paid / unpaid, cash / bank transfer that debits the chosen account. */
class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $acc = $this->inTenant($admin, fn () => BankAccount::create(['name' => 'Əsas AZN', 'bank_name' => 'Kapital Bank', 'currency' => 'AZN', 'opening_balance' => 5000, 'is_active' => true]));
        $this->actingAs($admin);

        return [$admin, $acc];
    }

    private function base(array $over = []): array
    {
        return $over + ['expense_date' => today()->subDays(3)->toDateString(), 'description' => 'Ofis icarəsi', 'amount' => '1 200,50', 'currency' => 'AZN'];
    }

    public function test_categories_section(): void
    {
        [$admin] = $this->world();
        $this->get(route('expenses.categories'))->assertOk()->assertSee('Yeni kateqoriya');
        $this->post(route('expenses.categories.store'), ['name' => 'İcarə', 'color' => '#ff0000'])->assertSessionHasNoErrors();
        $this->post(route('expenses.categories.store'), ['name' => 'İcarə'])->assertSessionHasErrors('name');
        $cat = $this->inTenant($admin, fn () => Category::where('scope', 'expense')->firstOrFail());
        $this->put(route('expenses.categories.update', $cat), ['name' => 'Ofis icarəsi'])->assertSessionHasNoErrors();
        $this->get(route('expenses.categories'))->assertSee('Ofis icarəsi');

        // A bank category is not an expense category.
        $bankCat = $this->inTenant($admin, fn () => Category::create(['scope' => 'bank', 'name' => 'Bank kat']));
        $this->delete(route('expenses.categories.destroy', $bankCat))->assertNotFound();
        $this->delete(route('expenses.categories.destroy', $cat))->assertRedirect();
        $this->assertSame(0, $this->inTenant($admin, fn () => Category::where('scope', 'expense')->count()));
        $this->assertTrue($this->inTenant($admin, fn () => Category::whereKey($bankCat->id)->exists()));
    }

    public function test_bank_transfer_debits_the_account_on_the_payment_date(): void
    {
        [$admin, $acc] = $this->world();
        $cat = $this->inTenant($admin, fn () => Category::create(['scope' => 'expense', 'name' => 'İcarə']));
        $paidAt = today()->subDay()->toDateString();

        $this->get(route('expenses.create'))->assertOk()->assertSee('Hesabdan köçürmə');
        $this->post(route('expenses.store'), $this->base(['category_id' => $cat->id, 'status' => 'paid', 'payment_method' => 'bank', 'paid_at' => $paidAt, 'bank_account_id' => $acc->id]))
            ->assertSessionHasNoErrors()->assertRedirect(route('expenses.index'));

        $e = $this->inTenant($admin, fn () => Expense::firstOrFail());
        $tx = $this->inTenant($admin, fn () => BankTransaction::firstOrFail());
        $this->assertSame($tx->id, $e->bank_transaction_id);
        $this->assertSame('out', $tx->direction);
        $this->assertSame($paidAt, $tx->transaction_date->toDateString());
        $this->assertSame(1200.5, (float) $tx->amount);
        $this->assertStringContainsString('İcarə', $tx->purpose);
        $this->assertSame(3799.5, $this->inTenant($admin, fn () => BankAccount::find($acc->id)->balance()));

        // Switch to cash: the debit is removed. Delete: nothing left on the account.
        $this->put(route('expenses.update', $e), $this->base(['status' => 'paid', 'payment_method' => 'cash', 'paid_at' => $paidAt]))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->inTenant($admin, fn () => BankTransaction::count()));
        $this->assertSame(5000.0, $this->inTenant($admin, fn () => BankAccount::find($acc->id)->balance()));

        $period = ['from' => today()->subMonth()->toDateString(), 'to' => today()->toDateString()];
        $this->get(route('expenses.index'))->assertOk();
        $this->get(route('expenses.index', $period))->assertOk()->assertSee('Ofis icarəsi')->assertSee('Nağd')->assertSee(money(1200.5));
        $this->get(route('expenses.index', $period + ['format' => 'xlsx']))->assertOk();
        $this->assertStringStartsWith('%PDF', $this->get(route('expenses.index', $period + ['format' => 'pdf']))->getContent());
    }

    public function test_unpaid_expense_is_paid_later_and_rules_are_enforced(): void
    {
        [$admin, $acc] = $this->world();
        $this->post(route('expenses.store'), $this->base(['status' => 'unpaid', 'due_date' => today()->addWeek()->toDateString()]))->assertSessionHasNoErrors();
        $e = $this->inTenant($admin, fn () => Expense::firstOrFail());
        $this->assertSame('unpaid', $e->status);
        $this->assertSame(0, $this->inTenant($admin, fn () => BankTransaction::count()));
        $this->get(route('expenses.index', ['status' => 'unpaid', 'from' => today()->subMonth()->toDateString(), 'to' => today()->toDateString()]))->assertSee('Ödənilməyib')->assertSee('Ödə');
        $this->get(route('expenses.edit', [$e, 'pay' => 1]))->assertOk();

        // Bank transfer needs an account in the same currency, and no future payment date.
        $this->put(route('expenses.update', $e), $this->base(['status' => 'paid', 'payment_method' => 'bank', 'paid_at' => today()->toDateString()]))->assertSessionHasErrors('bank_account_id');
        $usd = $this->inTenant($admin, fn () => BankAccount::create(['name' => 'USD', 'bank_name' => 'ABB', 'currency' => 'USD', 'is_active' => true]));
        $this->put(route('expenses.update', $e), $this->base(['status' => 'paid', 'payment_method' => 'bank', 'paid_at' => today()->toDateString(), 'bank_account_id' => $usd->id]))->assertSessionHasErrors('bank_account_id');
        $this->put(route('expenses.update', $e), $this->base(['status' => 'paid', 'payment_method' => 'bank', 'paid_at' => today()->addDay()->toDateString(), 'bank_account_id' => $acc->id]))->assertSessionHasErrors('paid_at');

        $this->put(route('expenses.update', $e), $this->base(['status' => 'paid', 'payment_method' => 'bank', 'paid_at' => today()->toDateString(), 'bank_account_id' => $acc->id]))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->inTenant($admin, fn () => BankTransaction::count()));

        $this->delete(route('expenses.destroy', $e))->assertRedirect();
        $this->assertSame(0, $this->inTenant($admin, fn () => BankTransaction::count()), 'deleting the expense removes the debit');

        $employee = $this->makeUser($admin->company, 'employee', 'isci@test.az');
        $this->actingAs($employee)->get(route('expenses.index'))->assertForbidden();
    }
}
