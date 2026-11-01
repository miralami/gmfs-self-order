<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\PaymentAttempt;
use App\Models\TenantOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKiosk;
use Tests\TestCase;

/**
 * The offline demo path used when the examiner has no sandbox key and no public
 * HTTPS tunnel. These commands are the fallback for the live webhook, so they
 * must keep going through the real signature check and state machine.
 */
class DemoCommandsTest extends TestCase
{
    use CreatesKiosk;
    use RefreshDatabase;

    public function test_the_offline_demo_commands_drive_a_real_paid_split(): void
    {
        $this->fakeSuccessfulCharge();
        [, , $firstProduct] = $this->tenantWithProduct('Noodle House', 'noodle-house', 50000);
        [, , $secondProduct] = $this->tenantWithProduct('Burger Point', 'burger-point', 60000);

        $reference = $this->placeCheckout([
            ['product_id' => $firstProduct->id, 'quantity' => 1],
            ['product_id' => $secondProduct->id, 'quantity' => 1],
        ])->assertCreated()->json('data.reference');

        // The charge already produced attempt 1; the demo command adds a fresh one.
        $this->artisan('payments:demo-charge', ['reference' => $reference])
            ->expectsOutputToContain('Demo attempt created')
            ->assertSuccessful();

        $this->artisan('payments:simulate', ['reference' => $reference])
            ->expectsOutputToContain('Notification outcome: paid')
            ->assertSuccessful();

        $checkout = Checkout::query()->where('reference', $reference)->firstOrFail();

        $this->assertSame('paid', $checkout->status);
        $this->assertSame(2, $checkout->paymentAttempts()->count());

        $orders = $checkout->tenantOrders()->get();

        $this->assertCount(2, $orders);
        $this->assertSame(110000, (int) $orders->sum('total_amount'));
        $this->assertSame(22000, (int) $orders->sum('platform_fee_amount'));
        $this->assertSame(88000, (int) $orders->sum('tenant_share_amount'));
        $this->assertSame([TenantOrder::STATUS_PAID], $orders->pluck('status')->unique()->values()->all());
    }

    public function test_demo_charge_refuses_a_checkout_that_is_already_paid(): void
    {
        $attempt = $this->paidCheckoutAttempt();

        $this->artisan('payments:demo-charge', ['reference' => $attempt->checkout->reference])
            ->expectsOutputToContain('not awaiting payment')
            ->assertFailed();
    }

    public function test_simulate_reports_an_unknown_reference_instead_of_failing_silently(): void
    {
        $this->artisan('payments:simulate', ['reference' => 'not-a-real-reference'])
            ->expectsOutputToContain('Checkout not found')
            ->assertFailed();
    }

    private function paidCheckoutAttempt(): PaymentAttempt
    {
        $this->fakeSuccessfulCharge();
        [, , $product] = $this->tenantWithProduct();

        $reference = $this->placeCheckout([['product_id' => $product->id, 'quantity' => 1]])
            ->assertCreated()
            ->json('data.reference');

        $this->artisan('payments:simulate', ['reference' => $reference])->assertSuccessful();

        return PaymentAttempt::query()->latest('id')->firstOrFail();
    }
}
