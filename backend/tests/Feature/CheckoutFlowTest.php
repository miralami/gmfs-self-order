<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\PaymentAttempt;
use App\Models\TenantOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesKiosk;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use CreatesKiosk;
    use RefreshDatabase;

    public function test_checkout_splits_into_one_unpaid_order_per_tenant(): void
    {
        $this->fakeSuccessfulCharge();
        [$firstTenant, , $firstProduct] = $this->tenantWithProduct('Noodle House', 'noodle-house', 50000);
        [$secondTenant, , $secondProduct] = $this->tenantWithProduct('Burger Point', 'burger-point', 60000);

        $response = $this->placeCheckout([
            ['product_id' => $firstProduct->id, 'quantity' => 2],
            ['product_id' => $secondProduct->id, 'quantity' => 1],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.total_amount', 160000)
            ->assertJsonCount(2, 'data.tenant_orders')
            ->assertJsonPath('meta.replayed', false)
            ->assertJsonPath('meta.payment_error', null);

        $checkout = Checkout::query()->firstOrFail();

        $this->assertSame(2, $checkout->tenantOrders()->count());
        $this->assertSame(160000, $checkout->total_amount);
        $this->assertSame(
            [TenantOrder::STATUS_UNPAID],
            $checkout->tenantOrders()->distinct()->pluck('status')->all(),
        );
        $this->assertSame(
            [$firstTenant->id, $secondTenant->id],
            $checkout->tenantOrders()->orderBy('tenant_id')->pluck('tenant_id')->all(),
        );
    }

    public function test_same_idempotency_key_returns_the_same_checkout(): void
    {
        $this->fakeSuccessfulCharge();
        [, , $product] = $this->tenantWithProduct();
        $key = 'client-intent-0001';
        $items = [['product_id' => $product->id, 'quantity' => 1]];

        $first = $this->placeCheckout($items, $key)->assertCreated();
        $second = $this->placeCheckout($items, $key)->assertOk();

        $this->assertSame(
            $first->json('data.reference'),
            $second->json('data.reference'),
        );
        $this->assertSame(true, $second->json('meta.replayed'));
        $this->assertDatabaseCount('checkouts', 1);
        $this->assertDatabaseCount('tenant_orders', 1);
        // A replay must not create a second charge.
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_same_key_with_a_different_cart_is_rejected(): void
    {
        $this->fakeSuccessfulCharge();
        [, , $product] = $this->tenantWithProduct();
        $key = 'client-intent-0002';

        $this->placeCheckout([['product_id' => $product->id, 'quantity' => 1]], $key)->assertCreated();

        $this->placeCheckout([['product_id' => $product->id, 'quantity' => 5]], $key)
            ->assertStatus(409);
    }

    public function test_checkout_requires_an_idempotency_key(): void
    {
        [, , $product] = $this->tenantWithProduct();

        $this->postJson('/api/v1/checkouts', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('checkouts', 0);
    }

    public function test_a_gateway_failure_leaves_an_unpaid_checkout_that_can_be_retried(): void
    {
        $calls = 0;

        Http::fake([
            '*/v2/charge' => function () use (&$calls) {
                $calls++;

                if ($calls === 1) {
                    return Http::response([
                        'status_code' => '402',
                        'status_message' => 'Merchant does not have access for this payment type',
                    ], 402);
                }

                return Http::response($this->successfulChargeBody(), 201);
            },
        ]);

        [, , $product] = $this->tenantWithProduct();

        $response = $this->placeCheckout([['product_id' => $product->id, 'quantity' => 1]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'unpaid');

        $this->assertNotNull($response->json('meta.payment_error'));

        $checkout = Checkout::query()->firstOrFail();
        $this->assertSame(PaymentAttempt::STATUS_FAILED, $checkout->paymentAttempts()->first()->status);

        // Fixing the gateway and retrying creates a second attempt, not a new checkout.
        $this->postJson('/api/v1/checkouts/'.$checkout->reference.'/payment-attempts')
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.attempt_no', 2);

        $this->assertSame(2, $checkout->paymentAttempts()->count());
    }

    public function test_preparation_queue_is_blocked_until_payment_is_confirmed(): void
    {
        $this->fakeSuccessfulCharge();
        [$tenant, , $product] = $this->tenantWithProduct();
        $manager = $this->managerFor($tenant);

        $this->placeCheckout([['product_id' => $product->id, 'quantity' => 1]], 'intent-unpaid')->assertCreated();

        $tenantOrder = TenantOrder::query()->firstOrFail();

        $this->withToken($manager['token'])
            ->postJson('/api/v1/tenant/orders/'.$tenantOrder->id.'/queue-preparation')
            ->assertStatus(422);
    }

    public function test_paid_order_can_be_queued_for_preparation(): void
    {
        $this->fakeSuccessfulCharge();
        [$tenant, , $product] = $this->tenantWithProduct();
        $manager = $this->managerFor($tenant);

        $this->placeCheckout([['product_id' => $product->id, 'quantity' => 1]], 'intent-paid');
        $attempt = PaymentAttempt::query()->firstOrFail();

        $this->postJson('/api/v1/payments/midtrans/notification', $this->notificationPayload($attempt))
            ->assertOk()
            ->assertJsonPath('outcome', 'paid');

        $tenantOrder = TenantOrder::query()->firstOrFail();

        $this->withToken($manager['token'])
            ->postJson('/api/v1/tenant/orders/'.$tenantOrder->id.'/queue-preparation')
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');

        // The order cannot be queued twice.
        $this->withToken($manager['token'])
            ->postJson('/api/v1/tenant/orders/'.$tenantOrder->id.'/queue-preparation')
            ->assertStatus(422);
    }
}
