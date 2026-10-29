<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seed data for the GMFS food court.
 *
 * GMFS has 13 tenants. The names below are placeholders in the same shape as the
 * real stalls; replace them with the list collected during the interview. The
 * first three tenants are the ones used in the demo and the test scenarios; the
 * other ten exist so the catalog and the tenant switcher look like the real
 * food court instead of a three-stall toy.
 */
class TenantCatalogSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, slug: string, products: array<int, array{0: string, 1: int}>}>
     */
    private array $tenants = [
        ['name' => 'Noodle House', 'slug' => 'noodle-house', 'products' => [['Beef Noodle', 50000], ['Chicken Noodle', 45000], ['Wonton Soup', 40000]]],
        ['name' => 'Burger Point', 'slug' => 'burger-point', 'products' => [['Classic Burger', 55000], ['Cheese Burger', 60000], ['French Fries', 25000]]],
        ['name' => 'Fresh Bowl', 'slug' => 'fresh-bowl', 'products' => [['Salad Bowl', 48000], ['Fruit Bowl', 35000], ['Infused Water', 15000]]],
        ['name' => 'Nasi Goreng Corner', 'slug' => 'nasi-goreng-corner', 'products' => [['Nasi Goreng Spesial', 42000], ['Nasi Goreng Seafood', 52000]]],
        ['name' => 'Mie Ayam Bangka', 'slug' => 'mie-ayam-bangka', 'products' => [['Mie Ayam Original', 38000], ['Mie Ayam Bakso', 45000]]],
        ['name' => 'Ayam Geprek Juara', 'slug' => 'ayam-geprek-juara', 'products' => [['Ayam Geprek', 35000], ['Ayam Geprek Keju', 42000]]],
        ['name' => 'Sate Madura', 'slug' => 'sate-madura', 'products' => [['Sate Ayam 10 Tusuk', 40000], ['Sate Kambing 10 Tusuk', 60000]]],
        ['name' => 'Bakso Pak Kumis', 'slug' => 'bakso-pak-kumis', 'products' => [['Bakso Urat', 30000], ['Bakso Telur', 35000]]],
        ['name' => 'Soto Betawi Haji', 'slug' => 'soto-betawi-haji', 'products' => [['Soto Betawi', 45000], ['Soto Ayam', 35000]]],
        ['name' => 'Dimsum Pagi', 'slug' => 'dimsum-pagi', 'products' => [['Siomay Ayam', 28000], ['Hakau Udang', 32000]]],
        ['name' => 'Es Teler Segar', 'slug' => 'es-teler-segar', 'products' => [['Es Teler', 25000], ['Es Campur', 22000]]],
        ['name' => 'Kopi Kenangan Kiosk', 'slug' => 'kopi-kenangan-kiosk', 'products' => [['Kopi Susu', 24000], ['Americano', 20000]]],
        ['name' => 'Martabak Manis', 'slug' => 'martabak-manis', 'products' => [['Martabak Cokelat', 45000], ['Martabak Keju', 50000]]],
    ];

    public function run(): void
    {
        $tenants = collect($this->tenants)->map(function (array $tenantData): Tenant {
            $tenant = Tenant::query()->create([
                'name' => $tenantData['name'],
                'slug' => $tenantData['slug'],
                'is_active' => true,
            ]);

            $outlet = Outlet::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->name.' - Outlet 1',
                'is_active' => true,
            ]);

            foreach ($tenantData['products'] as [$productName, $price]) {
                Product::query()->create([
                    'tenant_id' => $tenant->id,
                    'outlet_id' => $outlet->id,
                    'name' => $productName,
                    'price' => $price,
                    'is_active' => true,
                ]);
            }

            return $tenant;
        });

        User::query()->create([
            'name' => 'GMFS Operator',
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
