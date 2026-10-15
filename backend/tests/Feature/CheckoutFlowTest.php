<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_is_split_into_child_orders_per_tenant(): void
    {
        [$firstProduct, $secondProduct] = $this->createTwoTenantProducts();

        $response = $this->postJson('/api/v1/checkouts', [
            'payment_method' => 'mock_card',
            'items' => [
                ['product_id' => $firstProduct->id, 'quantity' => 2],
                ['product_id' => $secondProduct->id, 'quantity' => 1],
            ],
        ]);

        $response->assertCreated();
        $checkout = Checkout::firstOrFail();

        $this->assertSame(2, $checkout->tenantOrders()->count());
        $this->assertSame(160000, $checkout->total_cents);
    }

    public function test_failed_payment_does_not_allow_preparation_queue(): void
    {
        [$product] = $this->createTwoTenantProducts();
        $manager = $this->createTenantManager($product->tenant_id);

        $checkout = Checkout::query()->create([
            'reference' => (string) Str::uuid(),
            'total_cents' => 50000,
            'status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'attempt_count' => 1,
        ]);

        $tenantOrder = TenantOrder::query()->create([
            'checkout_id' => $checkout->id,
            'tenant_id' => $product->tenant_id,
            'outlet_id' => $product->outlet_id,
            'total_cents' => 50000,
            'status' => 'awaiting_payment',
        ]);

        $this->postJson("/api/v1/checkouts/{$checkout->id}/payments/mock/event", [
            'event_id' => 'evt-failed-1',
            'status' => 'failed',
            'reason' => 'Card declined',
        ])->assertOk();

        $this->withToken($manager['plain'])->postJson("/api/v1/tenant/orders/{$tenantOrder->id}/queue-preparation")
            ->assertStatus(422);
    }

    public function test_duplicate_payment_events_are_idempotent(): void
    {
        [$product] = $this->createTwoTenantProducts();

        $checkout = Checkout::query()->create([
            'reference' => (string) Str::uuid(),
            'total_cents' => 50000,
            'status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'attempt_count' => 1,
        ]);

        TenantOrder::query()->create([
            'checkout_id' => $checkout->id,
            'tenant_id' => $product->tenant_id,
            'outlet_id' => $product->outlet_id,
            'total_cents' => 50000,
            'status' => 'awaiting_payment',
        ]);

        $payload = [
            'event_id' => 'evt-paid-duplicate',
            'status' => 'paid',
            'payment_reference' => 'mock-123',
        ];

        $this->postJson("/api/v1/checkouts/{$checkout->id}/payments/mock/event", $payload)->assertOk();
        $this->postJson("/api/v1/checkouts/{$checkout->id}/payments/mock/event", $payload)->assertOk();

        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseHas('checkouts', [
            'id' => $checkout->id,
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);
    }

    public function test_checkout_retry_resets_failed_checkout_for_new_attempt(): void
    {
        [$product] = $this->createTwoTenantProducts();

        $checkout = Checkout::query()->create([
            'reference' => (string) Str::uuid(),
            'total_cents' => 50000,
            'status' => 'payment_failed',
            'payment_status' => 'failed',
            'attempt_count' => 1,
            'last_error' => 'Expired card',
        ]);

        TenantOrder::query()->create([
            'checkout_id' => $checkout->id,
            'tenant_id' => $product->tenant_id,
            'outlet_id' => $product->outlet_id,
            'total_cents' => 50000,
            'status' => 'awaiting_payment',
        ]);

        $this->postJson("/api/v1/checkouts/{$checkout->id}/retry", ['payment_method' => 'mock_card'])
            ->assertOk()
            ->assertJsonPath('data.attempt_count', 2)
            ->assertJsonPath('data.payment_status', 'pending');

        $this->assertDatabaseHas('checkouts', [
            'id' => $checkout->id,
            'attempt_count' => 2,
            'status' => 'awaiting_payment',
            'payment_status' => 'pending',
        ]);
    }

    public function test_tenant_manager_cannot_access_other_tenant_orders(): void
    {
        [$firstProduct, $secondProduct] = $this->createTwoTenantProducts();
        $firstManager = $this->createTenantManager($firstProduct->tenant_id);
        $secondManager = $this->createTenantManager($secondProduct->tenant_id);

        $checkout = Checkout::query()->create([
            'reference' => (string) Str::uuid(),
            'total_cents' => 120000,
            'status' => 'paid',
            'payment_status' => 'paid',
            'attempt_count' => 1,
        ]);

        $firstTenantOrder = TenantOrder::query()->create([
            'checkout_id' => $checkout->id,
            'tenant_id' => $firstProduct->tenant_id,
            'outlet_id' => $firstProduct->outlet_id,
            'total_cents' => 50000,
            'status' => 'paid',
            'payment_confirmed_at' => now(),
        ]);

        $secondTenantOrder = TenantOrder::query()->create([
            'checkout_id' => $checkout->id,
            'tenant_id' => $secondProduct->tenant_id,
            'outlet_id' => $secondProduct->outlet_id,
            'total_cents' => 70000,
            'status' => 'paid',
            'payment_confirmed_at' => now(),
        ]);

        $this->withToken($firstManager['plain'])->getJson('/api/v1/tenant/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstTenantOrder->id);

        $this->withToken($secondManager['plain'])->postJson("/api/v1/tenant/orders/{$firstTenantOrder->id}/queue-preparation")
            ->assertForbidden();

        $this->withToken($firstManager['plain'])->postJson("/api/v1/tenant/orders/{$secondTenantOrder->id}/queue-preparation")
            ->assertForbidden();
    }

    private function createTwoTenantProducts(): array
    {
        $firstTenant = Tenant::query()->create(['name' => 'T1', 'slug' => 't1', 'is_active' => true]);
        $secondTenant = Tenant::query()->create(['name' => 'T2', 'slug' => 't2', 'is_active' => true]);

        $firstOutlet = Outlet::query()->create(['tenant_id' => $firstTenant->id, 'name' => 'Outlet T1', 'is_active' => true]);
        $secondOutlet = Outlet::query()->create(['tenant_id' => $secondTenant->id, 'name' => 'Outlet T2', 'is_active' => true]);

        return [
            Product::query()->create([
                'tenant_id' => $firstTenant->id,
                'outlet_id' => $firstOutlet->id,
                'name' => 'Item T1',
                'price_cents' => 50000,
                'is_active' => true,
            ]),
            Product::query()->create([
                'tenant_id' => $secondTenant->id,
                'outlet_id' => $secondOutlet->id,
                'name' => 'Item T2',
                'price_cents' => 60000,
                'is_active' => true,
            ]),
        ];
    }

    private function createTenantManager(int $tenantId): array
    {
        $plainToken = Str::random(40);

        User::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Manager '.$tenantId,
            'email' => "manager{$tenantId}@example.com",
            'password' => 'password',
            'role' => 'tenant_manager',
            'api_token' => hash('sha256', $plainToken),
        ]);

        return ['plain' => $plainToken];
    }
}
