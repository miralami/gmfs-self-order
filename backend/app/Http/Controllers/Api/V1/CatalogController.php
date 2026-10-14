<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;

class CatalogController extends Controller
{
    public function tenants()
    {
        return response()->json([
            'data' => Tenant::query()
                ->where('is_active', true)
                ->with(['outlets:id,tenant_id,name,is_active', 'products:id,tenant_id,outlet_id,name,price_cents,is_active'])
                ->get(),
        ]);
    }

    public function products(Tenant $tenant)
    {
        return response()->json([
            'data' => $tenant->products()->where('is_active', true)->with('outlet:id,name,tenant_id')->get(),
        ]);
    }
}
