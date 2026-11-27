<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Support\Invoices\RubConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CalculatedInvoice;
use Tests\TestCase;

/** Step 3: the computed rate (e.g. 94,0594) can be typed over (94,06); the RUR figures use the typed rate. */
class RubRateEditTest extends TestCase
{
    use CalculatedInvoice, RefreshDatabase;

    public function test_hand_edited_rate_is_used_for_rur_prices(): void
    {
        [$admin, $inv] = $this->calculated();
        $this->applyRub($inv)->assertSessionHasNoErrors();

        $this->patch(route('invoices.rub.rate', $inv), ['fx_rate' => '0'])->assertSessionHasErrors('fx_rate');
        $this->patch(route('invoices.rub.rate', $inv), ['fx_rate' => '94,81'])->assertSessionHasNoErrors();

        $inv = $this->inTenant($admin, fn () => Invoice::with('items')->findOrFail($inv->id));
        $this->assertEqualsWithDelta(94.81, (float) $inv->fx_rate, 1e-9);
        $this->assertTrue(RubConverter::isOverridden($inv));
        $this->assertEqualsWithDelta(2.0005 / 0.0211, RubConverter::computed($inv), 1e-9);
        $item = $inv->items->first()->setRelation('invoice', $inv);
        $this->assertEqualsWithDelta($item->unitPriceCcl() * 94.81, $item->unitPriceRub(), 1e-6);

        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('94,8100')->assertSee(__('Əl ilə düzəldilib, hesablanan:'));

        // applying the rate again goes back to the computed one
        $this->applyRub($inv)->assertSessionHasNoErrors();
        $this->assertFalse(RubConverter::isOverridden($this->inTenant($admin, fn () => Invoice::findOrFail($inv->id))));
    }
}
