<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Tenant;
use App\Models\TenantOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesKiosk;
use Tests\TestCase;

class SettlementTest extends TestCase
{
    use CreatesKiosk;
    use RefreshDatabase;

    public function test_the_operator_summary_uses_the_stored_eighty_twenty_split(): void
    {
        [$firstTenant, $firstOutlet] = $this->tenantWithProduct('Noodle House', 'noodle-house', 50000);
        [$secondTenant, $secondOutlet] = $this->tenantWithProduct('Burger Point', 'burger-point', 60000);
        $operator = $this->operator();

        // 50,000 -> fee 10,000 / share 40,000. 33,333 -> fee 6,666 / share 26,667.
        $this->paidOrder($firstTenant, $firstOutlet, 50000);
        $this->paidOrder($firstTenant, $firstOutlet, 33333);
        $this->paidOrder($secondTenant, $secondOutlet, 60000);

        $response = $this->withToken($operator['token'])
            ->getJson('/api/v1/operator/settlements')
            ->assertOk()
            ->assertJsonPath('data.platform_fee_percent', 20);

        $rows = collect($response->json('data.rows'))->keyBy('tenant_id');

        $this->assertSame(2, $rows[$firstTenant->id]['order_count']);
        $this->assertSame(83333, $rows[$firstTenant->id]['gross_amount']);
        $this->assertSame(66667, $rows[$firstTenant->id]['tenant_share']);
        $this->assertSame(16666, $rows[$firstTenant->id]['platform_fee']);

        $this->assertSame(60000, $rows[$secondTenant->id]['gross_amount']);
        $this->assertSame(48000, $rows[$secondTenant->id]['tenant_share']);
        $this->assertSame(12000, $rows[$secondTenant->id]['platform_fee']);

        $totals = $response->json('data.totals');
        $this->assertSame(143333, $totals['gross_amount']);
        $this->assertSame($totals['gross_amount'], $totals['tenant_share'] + $totals['platform_fee']);
        $this->assertSame(3, $totals['order_count']);
    }

    public function test_unpaid_orders_are_not_settled(): void
    {
        [$tenant, $outlet] = $this->tenantWithProduct();
        $operator = $this->operator();

        $checkout = $this->checkout();
        TenantOrder::query()->create([
            'checkout_id' => $checkout->id,
            'tenant_id' => $tenant->id,
            'outlet_id' => $outlet->id,
            'public_code' => 'GMFS-UNPAID',
            'total_amount' => 50000,
            'status' => TenantOrder::STATUS_UNPAID,
        ]);

        $this->withToken($operator['token'])
            ->getJson('/api/v1/operator/settlements')
            ->assertOk()
            ->assertJsonCount(0, 'data.rows')
            ->assertJsonPath('data.totals.gross_amount', 0);
    }

    public function test_the_summary_can_be_filtered_by_payment_date(): void
    {
        [$tenant, $outlet] = $this->tenantWithProduct();
        $operator = $this->operator();

        $this->paidOrder($tenant, $outlet, 50000, now()->subDays(10));
        $this->paidOrder($tenant, $outlet, 60000, now());

        $this->withToken($operator['token'])
            ->getJson('/api/v1/operator/settlements?from='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.totals.gross_amount', 60000)
            ->assertJsonPath('data.totals.order_count', 1);
    }

    private function paidOrder(Tenant $tenant, $outlet, int $amount, $paidAt = null): TenantOrder
    {
        $paidAt ??= now();
        $fee = intdiv($amount * 20, 100);

        return TenantOrder::query()->create([
            'checkout_id' => $this->checkout()->id,
            'tenant_id' => $tenant->id,
            'outlet_id' => $outlet->id,
            'public_code' => 'GMFS-'.Str::upper(Str::random(6)),
            'total_amount' => $amount,
            'tenant_share_amount' => $amount - $fee,
            'platform_fee_amount' => $fee,
            'status' => TenantOrder::STATUS_PAID,
            'paid_at' => $paidAt,
        ]);
    }

    private function checkout(): Checkout
    {
        return Checkout::query()->create([
            'reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'seed'),
            'total_amount' => 0,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}
