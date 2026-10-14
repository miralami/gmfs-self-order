<?php

namespace App\Services;

use App\Models\Checkout;
use App\Models\CheckoutItem;
use App\Models\TenantOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutService
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    public function createCheckout(array $items): Checkout
    {
        $quote = $this->cartService->quote($items);

        return DB::transaction(function () use ($quote): Checkout {
            $checkout = Checkout::query()->create([
                'reference' => (string) Str::uuid(),
                'total_cents' => $quote['grand_total_cents'],
                'status' => 'awaiting_payment',
                'payment_status' => 'pending',
                'attempt_count' => 1,
            ]);

            foreach ($quote['items'] as $item) {
                CheckoutItem::query()->create([
                    'checkout_id' => $checkout->id,
                    'tenant_id' => $item['tenant_id'],
                    'outlet_id' => $item['outlet_id'],
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'unit_price_cents' => $item['unit_price_cents'],
                    'quantity' => $item['quantity'],
                    'line_total_cents' => $item['line_total_cents'],
                ]);
            }

            foreach ($quote['tenant_totals'] as $tenantId => $total) {
                $outletId = collect($quote['items'])->firstWhere('tenant_id', $tenantId)['outlet_id'];

                TenantOrder::query()->create([
                    'checkout_id' => $checkout->id,
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outletId,
                    'total_cents' => $total,
                    'status' => 'awaiting_payment',
                ]);
            }

            return $checkout->fresh(['items', 'tenantOrders']);
        });
    }

    public function retry(Checkout $checkout): Checkout
    {
        $checkout->update([
            'payment_status' => 'pending',
            'status' => 'awaiting_payment',
            'last_error' => null,
            'attempt_count' => $checkout->attempt_count + 1,
        ]);

        $checkout->tenantOrders()->update([
            'status' => 'awaiting_payment',
            'payment_confirmed_at' => null,
            'preparation_queued_at' => null,
        ]);

        return $checkout->fresh(['tenantOrders']);
    }
}
