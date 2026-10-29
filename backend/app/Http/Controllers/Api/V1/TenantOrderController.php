<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CheckoutItem;
use App\Models\TenantOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TenantOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = TenantOrder::query()
            ->visibleTo($request->user())
            ->with(['tenant:id,name', 'checkout:id,reference'])
            ->when($request->query('status'), fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->limit(100)
            ->get();

        $items = $this->itemsFor($orders);

        return response()->json([
            'data' => $orders->map(fn (TenantOrder $order): array => $this->present($order, $items)),
        ]);
    }

    public function show(Request $request, TenantOrder $tenantOrder): JsonResponse
    {
        $this->authorize('view', $tenantOrder);

        $tenantOrder->load(['tenant:id,name', 'checkout:id,reference']);

        $items = $this->itemsFor(collect([$tenantOrder]));

        return response()->json([
            'data' => $this->present($tenantOrder, $items),
        ]);
    }

    public function queuePreparation(Request $request, TenantOrder $tenantOrder): JsonResponse
    {
        $this->authorize('queuePreparation', $tenantOrder);

        if ($tenantOrder->status !== TenantOrder::STATUS_PAID || $tenantOrder->paid_at === null) {
            return response()->json([
                'message' => 'Payment must be confirmed before this order can be queued for preparation.',
            ], 422);
        }

        $tenantOrder->update([
            'status' => TenantOrder::STATUS_QUEUED,
            'queued_at' => now(),
        ]);

        $tenantOrder->load(['tenant:id,name', 'checkout:id,reference']);
        $items = $this->itemsFor(collect([$tenantOrder]));

        return response()->json([
            'data' => $this->present($tenantOrder, $items),
        ]);
    }

    /**
     * Load the items for a set of tenant orders in one query, keyed by
     * "checkout_id:tenant_id" so no order can pick up another tenant's items.
     *
     * @param  Collection<int, TenantOrder>  $orders
     * @return Collection<string, Collection<int, CheckoutItem>>
     */
    private function itemsFor(Collection $orders): Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }

        return CheckoutItem::query()
            ->whereIn('checkout_id', $orders->pluck('checkout_id')->unique()->values())
            ->whereIn('tenant_id', $orders->pluck('tenant_id')->unique()->values())
            ->get()
            ->groupBy(fn (CheckoutItem $item): string => $item->checkout_id.':'.$item->tenant_id);
    }

    /**
     * @param  Collection<string, Collection<int, CheckoutItem>>  $items
     * @return array<string, mixed>
     */
    private function present(TenantOrder $order, Collection $items): array
    {
        return [
            'id' => $order->id,
            'public_code' => $order->public_code,
            'tenant_id' => $order->tenant_id,
            'tenant_name' => $order->tenant?->name,
            'checkout_reference' => $order->checkout?->reference,
            'total_amount' => $order->total_amount,
            'tenant_share_amount' => $order->tenant_share_amount,
            'platform_fee_amount' => $order->platform_fee_amount,
            'status' => $order->status,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'queued_at' => $order->queued_at?->toIso8601String(),
            'items' => ($items->get($order->checkout_id.':'.$order->tenant_id) ?? collect())
                ->map(fn (CheckoutItem $item): array => [
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                ])
                ->values(),
        ];
    }
}
