<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function tenants(): JsonResponse
    {
        $tenants = Tenant::query()
            ->where('is_active', true)
            ->with([
                'outlets:id,tenant_id,name,is_active',
                'products' => fn ($query) => $query->where('is_active', true)->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $tenants->map(fn (Tenant $tenant): array => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'outlets' => $tenant->outlets,
                'products' => $tenant->products->map(fn ($product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'outlet_id' => $product->outlet_id,
                ]),
            ]),
        ]);
    }

    public function products(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'data' => $tenant->products()
                ->where('is_active', true)
                ->with('outlet:id,name,tenant_id')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
