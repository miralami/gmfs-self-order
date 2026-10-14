<?php

namespace App\Services;

use App\Models\Checkout;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\DB;

class MockPaymentService
{
    public function applyEvent(Checkout $checkout, string $eventId, string $status, array $payload = []): PaymentEvent
    {
        return DB::transaction(function () use ($checkout, $eventId, $status, $payload): PaymentEvent {
            $event = PaymentEvent::query()->firstOrCreate(
                ['event_id' => $eventId],
                [
                    'checkout_id' => $checkout->id,
                    'status' => $status,
                    'payload' => $payload,
                    'processed_at' => now(),
                ],
            );

            if (! $event->wasRecentlyCreated) {
                return $event;
            }

            if ($status === 'paid') {
                $checkout->update([
                    'status' => 'paid',
                    'payment_status' => 'paid',
                    'payment_reference' => $payload['payment_reference'] ?? $checkout->payment_reference,
                    'last_error' => null,
                ]);

                $checkout->tenantOrders()->update([
                    'status' => 'paid',
                    'payment_confirmed_at' => now(),
                ]);
            }

            if ($status === 'failed') {
                $checkout->update([
                    'status' => 'payment_failed',
                    'payment_status' => 'failed',
                    'last_error' => $payload['reason'] ?? 'Payment failed',
                ]);
            }

            return $event;
        });
    }
}
