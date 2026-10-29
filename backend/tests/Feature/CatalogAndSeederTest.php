<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAndSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_creates_the_thirteen_gmfs_tenants_and_one_manager_each(): void
    {
        $this->seed(TenantCatalogSeeder::class);

        $this->assertSame(13, Tenant::query()->count());
        $this->assertSame(13, User::query()->where('role', 'tenant_manager')->count());
        $this->assertSame(1, User::query()->where('role', 'operator')->count());
        $this->assertGreaterThanOrEqual(26, Product::query()->count());
    }

    public function test_the_public_catalog_exposes_prices_in_rupiah_and_no_internal_fields(): void
    {
        $this->seed(TenantCatalogSeeder::class);

        $response = $this->getJson('/api/v1/catalog/tenants')->assertOk();

        $this->assertCount(13, $response->json('data'));
        $firstProduct = $response->json('data.0.products.0');

        $this->assertArrayHasKey('price', $firstProduct);
        $this->assertIsInt($firstProduct['price']);
        $this->assertGreaterThan(0, $firstProduct['price']);
        $this->assertArrayNotHasKey('cost', $firstProduct);
    }

    public function test_quote_rejects_an_unknown_product(): void
    {
        $this->postJson('/api/v1/cart/quote', [
            'items' => [['product_id' => 999999, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items');
    }
}
