<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Models\Checkout;
use App\Models\CheckoutItem;
use App\Models\TenantOrder;
use App\Support\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutService
{
    public function __construct(private readonly CartService $cartService) {}

    /**
     * Create one checkout and its per-tenant child orders.
     *
     * Order splitting happens here, at checkout time, not at payment time. The
     * tenant order therefore exists as `unpaid` immediately, but it can only be
     * moved to the preparation queue after the payment is confirmed.
     *
     * @param  array<int, array{product_id: int|string, quantity: int|string}>  $items
     * @return array{checkout: Checkout, replayed: bool}
     *
     * @throws IdempotencyConflictException
     */
    public function create(array $items, string $idempotencyKey): array
    {
        $requestHash = $this->requestHash($items);

        $existing = Checkout::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $this->assertSameRequest($existing, $requestHash);

            return ['checkout' => $existing, 'replayed' => true];
        }

        try {
            $checkout = $this->createNewCheckout($items, $idempotencyKey, $requestHash);
        } catch (QueryException $exception) {
            if (! UniqueViolation::matches($exception)) {
                throw $exception;
            }

            // Two identical requests raced. The database kept one; return it.
            $existing = Checkout::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing === null) {
                throw $exception;
            }

            $this->assertSameRequest($existing, $requestHash);

            return ['checkout' => $existing, 'replayed' => true];
        }

        return ['checkout' => $checkout, 'replayed' => false];
    }

    /**
     * @param  array<int, array{product_id: int|string, quantity: int|string}>  $items
     */
    private function createNewCheckout(array $items, string $idempotencyKey, string $requestHash): Checkout
    {
        $quote = $this->cartService->quote($items);
        $ttlMinutes = (int) config('kiosk.checkout_ttl_minutes', 15);

        return DB::transaction(function () use ($quote, $idempotencyKey, $requestHash, $ttlMinutes): Checkout {
            $checkout = Checkout::query()->create([
                'reference' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'total_amount' => $quote['grand_total'],
                'status' => 'unpaid',
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);

            foreach ($quote['items'] as $item) {
                CheckoutItem::query()->create([
                    'checkout_id' => $checkout->id,
                    'tenant_id' => $item['tenant_id'],
                    'outlet_id' => $item['outlet_id'],
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'line_total' => $item['line_total'],
                ]);
            }

            foreach ($quote['tenant_totals'] as $tenantId => $tenantTotal) {
                $outletId = collect($quote['items'])->firstWhere('tenant_id', $tenantId)['outlet_id'];

                TenantOrder::query()->create([
                    'checkout_id' => $checkout->id,
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outletId,
                    'public_code' => $this->publicCode(),
                    'total_amount' => $tenantTotal,
                    'status' => TenantOrder::STATUS_UNPAID,
                ]);
            }

            return $checkout->fresh(['items', 'tenantOrders']);
        });
    }

    /**
     * A key may be replayed, but only for the same cart. Reusing it for a different
     * cart is a client bug and must not be silently accepted.
     *
     * @throws IdempotencyConflictException
     */
    private function assertSameRequest(Checkout $checkout, string $requestHash): void
    {
        if (! hash_equals($checkout->request_hash, $requestHash)) {
            throw new IdempotencyConflictException;
        }
    }

    /**
     * Canonical form of the cart, so item order does not change the hash.
     *
     * @param  array<int, array{product_id: int|string, quantity: int|string}>  $items
     */
    private function requestHash(array $items): string
    {
        $normalized = collect($items)
            ->map(fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ])
            ->sortBy('product_id')
            ->values()
            ->all();

        return hash('sha256', (string) json_encode($normalized));
    }

    private function publicCode(): string
    {
        $prefix = (string) config('kiosk.order_code_prefix', 'GMFS');

        return $prefix.'-'.Str::upper(Str::substr(Str::ulid()->toBase32(), -6));
    }
}
