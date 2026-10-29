<?php

namespace Tests\Feature;

use App\Models\TenantOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKiosk;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesKiosk;
    use RefreshDatabase;

    public function test_a_tenant_manager_only_sees_its_own_orders(): void
    {
        [$firstTenant, , $firstProduct] = $this->tenantWithProduct('Noodle House', 'noodle-house', 50000);
        [$secondTenant, , $secondProduct] = $this->tenantWithProduct('Burger Point', 'burger-point', 60000);
        $firstManager = $this->managerFor($firstTenant);
        $secondManager = $this->managerFor($secondTenant);

        $this->fakeSuccessfulCharge();
        $this->placeCheckout([
            ['product_id' => $firstProduct->id, 'quantity' => 1],
            ['product_id' => $secondProduct->id, 'quantity' => 1],
        ])->assertCreated();

        $firstOrder = TenantOrder::query()->where('tenant_id', $firstTenant->id)->firstOrFail();
        $secondOrder = TenantOrder::query()->where('tenant_id', $secondTenant->id)->firstOrFail();

        $this->withToken($firstManager['token'])->getJson('/api/v1/tenant/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstOrder->id);

        $this->withToken($secondManager['token'])->getJson('/api/v1/tenant/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondOrder->id);
    }

    public function test_guessing_another_tenants_order_id_returns_forbidden(): void
    {
        [$firstTenant, , $firstProduct] = $this->tenantWithProduct('Noodle House', 'noodle-house', 50000);
        [$secondTenant, , $secondProduct] = $this->tenantWithProduct('Burger Point', 'burger-point', 60000);
        $firstManager = $this->managerFor($firstTenant);

        $this->fakeSuccessfulCharge();
        $this->placeCheckout([
            ['product_id' => $firstProduct->id, 'quantity' => 1],
            ['product_id' => $secondProduct->id, 'quantity' => 1],
        ])->assertCreated();

        $secondOrder = TenantOrder::query()->where('tenant_id', $secondTenant->id)->firstOrFail();

        $this->withToken($firstManager['token'])
            ->getJson('/api/v1/tenant/orders/'.$secondOrder->id)
            ->assertForbidden();

        $this->withToken($firstManager['token'])
            ->postJson('/api/v1/tenant/orders/'.$secondOrder->id.'/queue-preparation')
            ->assertForbidden();
    }

    public function test_the_operator_sees_all_tenants_but_cannot_queue_orders(): void
    {
        [$firstTenant, , $firstProduct] = $this->tenantWithProduct('Noodle House', 'noodle-house', 50000);
        [, , $secondProduct] = $this->tenantWithProduct('Burger Point', 'burger-point', 60000);
        $operator = $this->operator();

        $this->fakeSuccessfulCharge();
        $this->placeCheckout([
            ['product_id' => $firstProduct->id, 'quantity' => 1],
            ['product_id' => $secondProduct->id, 'quantity' => 2],
        ])->assertCreated();

        $firstOrder = TenantOrder::query()->where('tenant_id', $firstTenant->id)->firstOrFail();

        $this->withToken($operator['token'])->getJson('/api/v1/tenant/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withToken($operator['token'])
            ->postJson('/api/v1/tenant/orders/'.$firstOrder->id.'/queue-preparation')
            ->assertForbidden();
    }

    public function test_guests_cannot_read_tenant_orders(): void
    {
        $this->getJson('/api/v1/tenant/orders')->assertUnauthorized();
        $this->getJson('/api/v1/operator/settlements')->assertUnauthorized();
    }

    public function test_only_the_operator_can_read_settlements(): void
    {
        [$tenant] = $this->tenantWithProduct();
        $manager = $this->managerFor($tenant);
        $operator = $this->operator();

        $this->withToken($manager['token'])->getJson('/api/v1/operator/settlements')->assertForbidden();
        $this->withToken($operator['token'])->getJson('/api/v1/operator/settlements')->assertOk();
    }

    public function test_public_checkout_status_proxies_the_qr_image_instead_of_exposing_the_gateway_url(): void
    {
        $this->fakeSuccessfulCharge();
        [, , $product] = $this->tenantWithProduct();

        $response = $this->placeCheckout([['product_id' => $product->id, 'quantity' => 1]])->assertCreated();
        $reference = $response->json('data.reference');

        $qrUrl = $this->getJson('/api/v1/checkouts/'.$reference)
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonMissingPath('data.payment.gateway_response')
            ->json('data.payment.qr_image_url');

        $this->assertStringContainsString('/api/v1/checkouts/'.$reference.'/qris', $qrUrl);
        $this->assertStringNotContainsString('midtrans.test', $qrUrl);
    }
}
