<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantOrder;

class TenantOrderController extends Controller
{
    public function index()
    {
        $user = request()->user();

        $query = TenantOrder::query()->with(['checkout:id,reference', 'tenant:id,name']);

        if ($user->isTenantManager()) {
            $query->where('tenant_id', $user->tenant_id);
        }

        return response()->json(['data' => $query->latest()->get()]);
    }

    public function queuePreparation(TenantOrder $tenantOrder)
    {
        $this->authorize('queuePreparation', $tenantOrder);

        if ($tenantOrder->payment_confirmed_at === null || $tenantOrder->status !== 'paid') {
            return response()->json(['message' => 'Payment must be confirmed before preparation queueing.'], 422);
        }

        $tenantOrder->update([
            'status' => 'in_preparation',
            'preparation_queued_at' => now(),
        ]);

        return response()->json(['data' => $tenantOrder->fresh()]);
    }
}
