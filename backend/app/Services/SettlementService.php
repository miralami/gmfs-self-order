<?php

namespace App\Services;

use App\Models\TenantOrder;
use Illuminate\Support\Carbon;

class SettlementService
{
    public function summary(?string $fromDate, ?string $toDate): array
    {
        $query = TenantOrder::query()
            ->selectRaw('tenant_id, count(*) as order_count, sum(total_cents) as total_cents')
            ->with('tenant:id,name')
            ->where('status', 'paid')
            ->groupBy('tenant_id');

        if ($fromDate) {
            $query->whereDate('payment_confirmed_at', '>=', Carbon::parse($fromDate));
        }

        if ($toDate) {
            $query->whereDate('payment_confirmed_at', '<=', Carbon::parse($toDate));
        }

        return $query->get()->map(fn (TenantOrder $order) => [
            'tenant_id' => $order->tenant_id,
            'tenant_name' => $order->tenant?->name,
            'order_count' => (int) $order->order_count,
            'total_cents' => (int) $order->total_cents,
        ])->values()->all();
    }
}
