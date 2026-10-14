<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function quote(array $items): array
    {
        $productMap = Product::query()
            ->with(['tenant:id,name', 'outlet:id,name'])
            ->whereIn('id', collect($items)->pluck('product_id'))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $lineItems = [];
        $tenantTotals = [];
        $grandTotal = 0;

        foreach ($items as $item) {
            $product = $productMap->get($item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => "Product {$item['product_id']} is unavailable.",
                ]);
            }

            $lineTotal = $product->price_cents * $item['quantity'];
            $grandTotal += $lineTotal;

            $tenantTotals[$product->tenant_id] = ($tenantTotals[$product->tenant_id] ?? 0) + $lineTotal;

            $lineItems[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'tenant_id' => $product->tenant_id,
                'tenant_name' => $product->tenant->name,
                'outlet_id' => $product->outlet_id,
                'outlet_name' => $product->outlet->name,
                'unit_price_cents' => $product->price_cents,
                'quantity' => $item['quantity'],
                'line_total_cents' => $lineTotal,
            ];
        }

        return [
            'items' => $lineItems,
            'tenant_totals' => $tenantTotals,
            'grand_total_cents' => $grandTotal,
        ];
    }
}
