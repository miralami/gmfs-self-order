<?php

namespace App\Http\Resources;

use App\Models\Checkout;
use App\Models\CheckoutItem;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Checkout
 */
class CheckoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latestAttempt = $this->paymentAttempts
            ->sortByDesc('attempt_no')
            ->first();

        return [
            'reference' => $this->reference,
            'status' => $this->status,
            'total_amount' => $this->total_amount,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (CheckoutItem $item): array => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'tenant_id' => $item->tenant_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
            ])->values()),
            'tenant_orders' => $this->whenLoaded('tenantOrders', fn () => $this->tenantOrders->map(fn ($order): array => [
                'public_code' => $order->public_code,
                'tenant_id' => $order->tenant_id,
                'tenant_name' => $order->tenant?->name,
                'total_amount' => $order->total_amount,
                'status' => $order->status,
                'items' => $this->items
                    ->where('tenant_id', $order->tenant_id)
                    ->map(fn (CheckoutItem $item): array => [
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'line_total' => $item->line_total,
                    ])
                    ->values(),
            ])->values()),
            'payment' => $this->paymentPayload($latestAttempt),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paymentPayload(?PaymentAttempt $attempt): ?array
    {
        if ($attempt === null) {
            return null;
        }

        return [
            'attempt_no' => $attempt->attempt_no,
            'status' => $attempt->status,
            'amount' => $attempt->amount,
            'qr_string' => $attempt->qr_string,
            'qr_image_url' => $attempt->qr_image_url !== null
                ? url('/api/v1/checkouts/'.$this->reference.'/qris')
                : null,
            'expires_at' => $attempt->expires_at?->toIso8601String(),
            'failure_reason' => $attempt->failure_reason,
        ];
    }
}
