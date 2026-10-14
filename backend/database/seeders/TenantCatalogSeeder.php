<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class TenantCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = collect([
            ['name' => 'Noodle House', 'slug' => 'noodle-house', 'products' => [['Beef Noodle', 50000], ['Chicken Noodle', 45000]]],
            ['name' => 'Burger Point', 'slug' => 'burger-point', 'products' => [['Classic Burger', 55000], ['Cheese Burger', 60000]]],
            ['name' => 'Fresh Bowl', 'slug' => 'fresh-bowl', 'products' => [['Salad Bowl', 48000], ['Fruit Bowl', 35000]]],
        ])->map(function (array $tenantData) {
            $tenant = Tenant::query()->create([
                'name' => $tenantData['name'],
                'slug' => $tenantData['slug'],
                'is_active' => true,
            ]);

            $outlet = Outlet::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->name.' Main Outlet',
                'is_active' => true,
            ]);

            foreach ($tenantData['products'] as [$productName, $price]) {
                Product::query()->create([
                    'tenant_id' => $tenant->id,
                    'outlet_id' => $outlet->id,
                    'name' => $productName,
                    'price_cents' => $price,
                    'is_active' => true,
                ]);
            }

            return $tenant;
        });

        User::query()->create([
            'name' => 'Operator',
            'email' => 'operator@example.com',
            'password' => 'password',
            'role' => 'operator',
            'api_token' => hash('sha256', 'operator-token'),
        ]);

        foreach ($tenants as $tenant) {
            User::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->name.' Manager',
                'email' => $tenant->slug.'@example.com',
                'password' => 'password',
                'role' => 'tenant_manager',
                'api_token' => hash('sha256', $tenant->slug.'-token'),
            ]);
        }
    }
}
