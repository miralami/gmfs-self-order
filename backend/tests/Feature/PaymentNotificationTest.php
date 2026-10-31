<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\PaymentAttempt;
use App\Models\PaymentNotification;
use App\Models\TenantOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesKiosk;
use Tests\TestCase;

class PaymentNotificationTest extends TestCase
{
    use CreatesKiosk;
    use RefreshDatabase;

    public function test_a_valid_settlement_notification_pays_the_checkout_and_splits_each_tenant_order(): void
    {
        $attempt = $this->placeSingleTenantCheckout(amount: 50000);
        $checkout = $attempt->checkout;

        $this->postJson('/api/v1/payments/midtrans/notification', $this->notificationPayload($attempt))
            ->assertOk()
            ->assertJsonPath('outcome', 'paid');

        $checkout->refresh();
        $this->assertSame('paid', $checkout->status);
        $this->assertNotNull($checkout->paid_at);

        $order = $checkout->tenantOrders()->firstOrFail();
        $this->assertSame(TenantOrder::STATUS_PAID, $order->status);
        $this->assertSame(10000, $order->platform_fee_amount);
        $this->assertSame(40000, $order->tenant_share_amount);
        $this->assertSame($order->total_amount, $order->platform_fee_amount + $order->tenant_share_amount);
    }

    public function test_the_split_floors_the_fee_so_share_and_fee_sum_to_the_total(): void
    {
        // 20% of 33,333 is 6,666.6. Rounding would lose one rupiah somewhere;
        // flooring the fee and giving the remainder to the tenant cannot.
        $attempt = $this->placeSingleTenantCheckout(amount: 33333);

        $this->postJson('/api/v1/payments/midtrans/notification', $this->notificationPayload($attempt))
            ->assertOk()
            ->assertJsonPath('outcome', 'paid');

        $order = $attempt->checkout->fresh()->tenantOrders()->firstOrFail();

        $this->assertSame(6666, $order->platform_fee_amount);
        $this->assertSame(26667, $order->tenant_share_amount);
        $this->assertSame(
            $order->total_amount,
            $order->platform_fee_amount + $order->tenant_share_amount,
        );
    }

    public function test_tiny_and_odd_amounts_still_split_into_whole_rupiah(): void
    {
        foreach ([1, 7777, 50001] as $index => $amount) {
            $attempt = $this->placeSingleTenantCheckout(amount: $amount, slug: 'tenant-edge-'.$index);

            $this->postJson('/api/v1/payments/midtrans/notification', $this->notificationPayload($attempt))
                ->assertOk()
                ->assertJsonPath('outcome', 'paid');

            $order = $attempt->checkout->fresh()->tenantOrders()->firstOrFail();

            $this->assertSame(intdiv($amount * 20, 100), $order->platform_fee_amount, 'fee for '.$amount);
            $this->assertSame(
                $amount,
                $order->platform_fee_amount + $order->tenant_share_amount,
                'split for '.$amount,
            );
            $this->assertGreaterThanOrEqual(0, $order->tenant_share_amount);
        }
    }

    public function test_replayed_notifications_produce_one_paid_state_and_one_split(): void
    {
        $attempt = $this->placeSingleTenantCheckout(amount: 50000);
        $checkout = $attempt->checkout;
        $payload = $this->notificationPayload($attempt);

        $this->postJson('/api/v1/payments/midtrans/notification', $payload)->assertOk()->assertJsonPath('outcome', 'paid');
        $paidAt = $checkout->fresh()->paid_at;

        $this->postJson('/api/v1/payments/midtrans/notification', $payload)->assertOk()->assertJsonPath('outcome', 'duplicate');
        $this->postJson('/api/v1/payments/midtrans/notification', $payload)->assertOk()->assertJsonPath('outcome', 'duplicate');

        $this->assertDatabaseCount('payment_notifications', 1);
        $this->assertDatabaseCount('tenant_orders', 1);
        $this->assertSame($paidAt->toIso8601String(), $checkout->fresh()->paid_at->toIso8601String());
    }

    public function test_an_invalid_signature_changes_nothing(): void
    {
        $attempt = $this->placeSingleTenantCheckout(amount: 50000);
        $payload = $this->notificationPayload($attempt);
        $payload['signature_key'] = str_repeat('0', 128);

        $this->postJson('/api/v1/payments/midtrans/notification', $payload)
            ->assertStatus(403);

        $this->assertSame('unpaid', $attempt->checkout->fresh()->status);
        $this->assertSame(TenantOrder::STATUS_UNPAID, $attempt->checkout->tenantOrders()->first()->status);
        $this->assertDatabaseHas('payment_notifications', [
            'gateway_order_id' => $attempt->gateway_order_id,
            'signature_valid' => false,
            'outcome' => 'invalid_signature',
        ]);
    }

    public function test_an_amount_mismatch_is_flagged_instead_of_paid(): void
    {
        $attempt = $this->placeSingleTenantCheckout(amount: 50000);

        $this->postJson('/api/v1/payments/midtrans/notification', $this->notificationPayload($attempt, grossAmount: '1000.00'))
            ->assertOk()
            ->assertJsonPath('outcome', 'amount_mismatch');

        $this->assertSame('unpaid', $attempt->checkout->fresh()->status);
        $this->assertStringContainsString('Amount mismatch', (string) $attempt->fresh()->failure_reason);
    }

    public function test_a_checkout_that_has_expired_does_not_become_paid(): void
    {
        $attempt = $this->placeSingleTenantCheckout(amount: 50000);
        $attempt->checkout->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/payments/midtrans/notification', $this->notificationPayload($attempt))
            ->assertOk()
            ->assertJsonPath('outcome', 'late_payment');

        $checkout = $attempt->checkout->fresh();
        $this->assertSame('unpaid', $checkout->status);
        $this->assertNull($checkout->paid_at);
        $this->assertSame(PaymentAttempt::STATUS_CANCELLED, $attempt->fresh()->status);
    }

    public function test_a_second_settlement_after_payment_is_recorded_as_already_paid(): void
    {
        $attempt = $this->placeSingleTenantCheckout(amount: 50000);

        $this->postJson('/api/v1/payments/midtrans/notification', $this->notificationPayload($attempt))
            ->assertOk()
            ->assertJsonPath('outcome', 'paid');

        // A different status_code means it is not a byte-identical replay, so it
        // reaches the state machine and must still be refused.
        $this->postJson('/api/v1/payments/midtrans/notification', $this->notificationPayload($attempt, statusCode: '201'))
            ->assertOk()
            ->assertJsonPath('outcome', 'already_paid');

        $this->assertSame(1, $attempt->checkout->tenantOrders()->count());
    }

    public function test_an_unknown_order_is_recorded_but_not_applied(): void
    {
        $orderId = 'GMFS-unknown-1';
        $gross = '50000.00';
        $signature = hash('sha512', $orderId.'200'.$gross.config('services.midtrans.server_key'));

        $this->postJson('/api/v1/payments/midtrans/notification', [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $gross,
            'transaction_status' => 'settlement',
            'signature_key' => $signature,
        ])->assertOk()->assertJsonPath('outcome', 'unknown_order');

        $this->assertDatabaseHas('payment_notifications', [
            'gateway_order_id' => $orderId,
            'outcome' => 'unknown_order',
        ]);
        $this->assertSame(0, Checkout::query()->where('status', 'paid')->count());
    }

    public function test_expiry_command_closes_unpaid_checkouts_and_cancels_their_orders(): void
    {
        $attempt = $this->placeSingleTenantCheckout(amount: 50000);
        $attempt->checkout->update(['expires_at' => now()->subMinute()]);

        $this->artisan('checkouts:expire')->assertSuccessful();

        $this->assertSame('expired', $attempt->checkout->fresh()->status);
        $this->assertSame(TenantOrder::STATUS_CANCELLED, $attempt->checkout->tenantOrders()->first()->status);
        $this->assertSame(PaymentAttempt::STATUS_EXPIRED, $attempt->fresh()->status);
    }

    public function test_reconciliation_repairs_a_lost_notification(): void
    {
        $attempt = $this->placeSingleTenantCheckout(amount: 50000);
        PaymentAttempt::query()->whereKey($attempt->id)->update(['created_at' => now()->subMinutes(5)]);
        $attempt->checkout->update(['expires_at' => now()->addMinutes(10)]);

        Http::fake([
            '*/v2/*/status' => Http::response([
                'status_code' => '200',
                'transaction_id' => $attempt->transaction_id,
                'order_id' => $attempt->gateway_order_id,
                'gross_amount' => number_format($attempt->amount, 2, '.', ''),
                'payment_type' => 'qris',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
                'transaction_time' => now()->format('Y-m-d H:i:s'),
            ], 200),
        ]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('paid', $attempt->checkout->fresh()->status);
        $this->assertSame(TenantOrder::STATUS_PAID, $attempt->checkout->tenantOrders()->first()->status);
        $this->assertSame(1, PaymentNotification::query()->count());
    }

    public function test_a_forged_notification_cannot_block_the_genuine_one(): void
    {
        $attempt = $this->placeSingleTenantCheckout(amount: 50000);
        $genuine = $this->notificationPayload($attempt);

        $forged = $genuine;
        $forged['signature_key'] = str_repeat('a', 128);

        $this->postJson('/api/v1/payments/midtrans/notification', $forged)->assertStatus(403);
        $this->assertSame('unpaid', $attempt->checkout->fresh()->status);

        // The forged delivery was stored for the audit trail, but the genuine one
        // still applies: the signature is part of the event identity.
        $this->postJson('/api/v1/payments/midtrans/notification', $genuine)
            ->assertOk()
            ->assertJsonPath('outcome', 'paid');

        $this->assertSame('paid', $attempt->checkout->fresh()->status);
        $this->assertSame(2, PaymentNotification::query()->count());
    }

    public function test_a_late_expire_event_cannot_close_a_paid_checkout(): void
    {
        $attempt = $this->placeSingleTenantCheckout(amount: 50000);
        $checkout = $attempt->checkout;

        $this->postJson('/api/v1/payments/midtrans/notification', $this->notificationPayload($attempt))
            ->assertOk()
            ->assertJsonPath('outcome', 'paid');

        // A stale attempt that existed before the successful one.
        $stale = PaymentAttempt::query()->create([
            'checkout_id' => $checkout->id,
            'attempt_no' => 2,
            'gateway' => 'midtrans',
            'payment_method' => 'qris',
            'gateway_order_id' => 'GMFS-stale-2',
            'status' => PaymentAttempt::STATUS_PENDING,
            'amount' => $checkout->total_amount,
        ]);

        $this->postJson('/api/v1/payments/midtrans/notification', $this->notificationPayload($stale, 'expire', '202'))
            ->assertOk()
            ->assertJsonPath('outcome', 'expired');

        $this->assertSame('paid', $checkout->fresh()->status);
        $this->assertSame(TenantOrder::STATUS_PAID, $checkout->tenantOrders()->first()->status);
    }

    public function test_a_delayed_expire_for_a_cancelled_attempt_is_ignored(): void
    {
        $attempt = $this->placeSingleTenantCheckout(amount: 50000);
        $attempt->update(['status' => PaymentAttempt::STATUS_CANCELLED]);

        $this->postJson('/api/v1/payments/midtrans/notification', $this->notificationPayload($attempt, 'expire', '202'))
            ->assertOk()
            ->assertJsonPath('outcome', 'ignored');

        $this->assertSame(PaymentAttempt::STATUS_CANCELLED, $attempt->fresh()->status);
    }

    private function placeSingleTenantCheckout(int $amount, string $slug = 'tenant-one'): PaymentAttempt
    {
        $this->fakeSuccessfulCharge();
        [, , $product] = $this->tenantWithProduct(price: $amount, slug: $slug);

        $this->placeCheckout([['product_id' => $product->id, 'quantity' => 1]])->assertCreated();

        return PaymentAttempt::query()->latest('id')->firstOrFail();
    }
}
