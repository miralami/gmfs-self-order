<?php

namespace App\Services;

use App\Models\TenantOrder;
use Illuminate\Support\Carbon;

class SettlementService
{
    /**
     * Cross-tenant settlement summary for the operator.
     *
     * Only paid tenant orders count. The split was fixed when the payment was
     * confirmed (see PaymentService::markPaid), so the numbers here are a
     * replay of stored values, not a new calculation.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, int>, platform_fee_percent: int}
     */
    public function summary(?string $fromDate, ?string $toDate): array
    {
        $query = TenantOrder::query()
            ->selectRaw('tenant_id, count(*) as order_count, sum(total_amount) as gross_amount, sum(tenant_share_amount) as tenant_share, sum(platform_fee_amount) as platform_fee')
            ->with('tenant:id,name')
            ->whereIn('status', [TenantOrder::STATUS_PAID, TenantOrder::STATUS_QUEUED])
            ->whereNotNull('paid_at')
            ->groupBy('tenant_id');

        if ($fromDate) {
            $query->where('paid_at', '>=', Carbon::parse($fromDate)->startOfDay());
        }

        if ($toDate) {
            $query->where('paid_at', '<=', Carbon::parse($toDate)->endOfDay());
        }

        $rows = $query->get()
            ->map(fn (TenantOrder $order): array => [
                'tenant_id' => $order->tenant_id,
                'tenant_name' => $order->tenant?->name,
                'order_count' => (int) $order->order_count,
                'gross_amount' => (int) $order->gross_amount,
                'tenant_share' => (int) $order->tenant_share,
                'platform_fee' => (int) $order->platform_fee,
            ])
            ->sortBy('tenant_name')
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'totals' => [
                'order_count' => array_sum(array_column($rows, 'order_count')),
                'gross_amount' => array_sum(array_column($rows, 'gross_amount')),
                'tenant_share' => array_sum(array_column($rows, 'tenant_share')),
                'platform_fee' => array_sum(array_column($rows, 'platform_fee')),
            ],
            'platform_fee_percent' => (int) config('kiosk.platform_fee_percent', 20),
        ];
    }
}
