<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Exceptions\PaymentStateException;
use App\Models\Checkout;
use App\Models\PaymentAttempt;
use App\Models\PaymentNotification;
use App\Models\TenantOrder;
use App\Support\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The durable layer around Midtrans.
 *
 * Midtrans itself only guarantees idempotency for five minutes through the
 * Idempotency-Key header, so the application keeps its own guard rails:
 *
 * 1. One payment attempt row per attempt, with a unique gateway_order_id.
 * 2. One notification row per (order_id, transaction_status, status_code).
 * 3. A monotonic checkout state: unpaid -> paid|expired|cancelled.
 * 4. A unique (checkout_id, tenant_id) index, so a checkout can never be split twice.
 */
class PaymentService
{
    public function __construct(private readonly MidtransGateway $gateway) {}

    public function gatewayConfigured(): bool
    {
        return $this->gateway->isConfigured();
    }

    /**
     * Create a new QRIS attempt for an unpaid checkout.
     *
     * @throws PaymentStateException
     * @throws PaymentGatewayException
     */
    public function startAttempt(Checkout $checkout): PaymentAttempt
    {
        $checkout = $checkout->fresh();

        if (! $checkout->isUnpaid()) {
            throw new PaymentStateException('This checkout is not awaiting payment.');
        }

        if ($checkout->hasExpired()) {
            throw new PaymentStateException('This checkout has expired. Start a new checkout.');
        }

        // Expire the previous QR at the gateway before opening a new one. This is
        // best effort: the application still refuses a second paid state.
        foreach ($checkout->paymentAttempts()->where('status', PaymentAttempt::STATUS_PENDING)->get() as $previous) {
            $this->gateway->expire($previous->gateway_order_id);
        }

        $attempt = DB::transaction(function () use ($checkout): PaymentAttempt {
            $locked = Checkout::query()->whereKey($checkout->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isUnpaid() || $locked->hasExpired()) {
                throw new PaymentStateException('This checkout can no longer be paid.');
            }

            $locked->paymentAttempts()
                ->where('status', PaymentAttempt::STATUS_PENDING)
                ->update([
                    'status' => PaymentAttempt::STATUS_CANCELLED,
                    'failure_reason' => 'Replaced by a newer attempt.',
                ]);

            $attemptNo = ((int) $locked->paymentAttempts()->max('attempt_no')) + 1;

            return $locked->paymentAttempts()->create([
                'attempt_no' => $attemptNo,
                'gateway' => 'midtrans',
                'payment_method' => 'qris',
                'gateway_order_id' => $this->gatewayOrderId($locked, $attemptNo),
                'status' => PaymentAttempt::STATUS_PENDING,
                'amount' => $locked->total_amount,
            ]);
        });

        try {
            $response = $this->gateway->chargeQris($attempt->gateway_order_id, $attempt->amount);
        } catch (PaymentGatewayException $exception) {
            $attempt->update([
                'status' => PaymentAttempt::STATUS_FAILED,
                'failure_reason' => Str::limit($exception->getMessage(), 490),
            ]);

            throw $exception;
        }

        $ttlMinutes = (int) config('kiosk.checkout_ttl_minutes', 15);

        $attempt->update([
            'transaction_id' => isset($response['transaction_id']) ? (string) $response['transaction_id'] : null,
            'qr_string' => isset($response['qr_string']) ? (string) $response['qr_string'] : null,
            'qr_image_url' => $this->gateway->qrImageUrl($response),
            'gateway_response' => $response,
            'expires_at' => $this->gateway->expiryFromResponse($response) ?? now()->addMinutes($ttlMinutes),
        ]);

        return $attempt->fresh();
    }

    /**
     * Apply a Midtrans notification. Returns the decision, which is also stored
     * on the notification row for the audit trail shown in BAB IV.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyNotification(array $payload, bool $trusted = false): string
    {
        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $transactionStatus = (string) ($payload['transaction_status'] ?? '');
        $fraudStatus = isset($payload['fraud_status']) ? (string) $payload['fraud_status'] : null;
        $signature = (string) ($payload['signature_key'] ?? '');

        if ($orderId === '' || $transactionStatus === '') {
            return 'malformed';
        }

        // A trusted Get Status response has no signature, because it is not a
        // notification. Synthesise the signature the gateway would have sent so the
        // audit row is consistent and repeated reconciliation runs dedupe normally.
        if ($signature === '' && $trusted) {
            $signature = $this->gateway->signatureKey($orderId, $statusCode, $grossAmount);
        }

        $signatureValid = $trusted
            || ($signature !== '' && hash_equals(
                $this->gateway->signatureKey($orderId, $statusCode, $grossAmount),
                $signature,
            ));

        $attempt = PaymentAttempt::query()->where('gateway_order_id', $orderId)->first();

        try {
            return DB::transaction(function () use ($attempt, $payload, $orderId, $statusCode, $transactionStatus, $fraudStatus, $signature, $signatureValid): string {
                $notification = PaymentNotification::query()->create([
                    'payment_attempt_id' => $attempt?->id,
                    'gateway_order_id' => $orderId,
                    'transaction_status' => $transactionStatus,
                    'status_code' => $statusCode,
                    'fraud_status' => $fraudStatus,
                    'signature_key' => $signature !== '' ? $signature : null,
                    'signature_valid' => $signatureValid,
                    'outcome' => $signatureValid ? 'received' : 'invalid_signature',
                    'payload' => $payload,
                ]);

                $outcome = $signatureValid
                    ? $this->decide($attempt, $payload)
                    : 'invalid_signature';

                $notification->update([
                    'outcome' => $outcome,
                    'processed_at' => now(),
                ]);

                return $outcome;
            });
        } catch (QueryException $exception) {
            if (UniqueViolation::matches($exception)) {
                return 'duplicate';
            }

            throw $exception;
        }
    }

    /**
     * Ask Midtrans for the current status of attempts that never received a
     * notification. This is the repair path when a webhook is lost.
     *
     * @return array{checked: int, updated: int, failed: int}
     */
    public function reconcilePending(int $limit = 50): array
    {
        $attempts = PaymentAttempt::query()
            ->where('status', PaymentAttempt::STATUS_PENDING)
            ->where('created_at', '<=', now()->subMinutes(2))
            ->whereHas('checkout', fn ($query) => $query->where('status', 'unpaid'))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $updated = 0;
        $failed = 0;

        foreach ($attempts as $attempt) {
            try {
                $payload = $this->gateway->status($attempt->gateway_order_id);
            } catch (PaymentGatewayException) {
                $failed++;

                continue;
            }

            $before = $attempt->status;
            $outcome = $this->applyNotification($payload, trusted: true);

            if ($outcome !== 'pending' && $outcome !== 'duplicate' && $outcome !== 'ignored' && $before !== $attempt->fresh()->status) {
                $updated++;
            }
        }

        return ['checked' => $attempts->count(), 'updated' => $updated, 'failed' => $failed];
    }

    /**
     * Expire unpaid checkouts whose payment window has closed.
     */
    public function expireStaleCheckouts(int $limit = 200): int
    {
        $checkouts = Checkout::query()
            ->where('status', 'unpaid')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $expired = 0;

        foreach ($checkouts as $checkout) {
            $didExpire = DB::transaction(function () use ($checkout): bool {
                $locked = Checkout::query()->whereKey($checkout->id)->lockForUpdate()->first();

                // Re-check under the lock: a notification may have paid it while this
                // command was iterating.
                if ($locked === null || ! $locked->isUnpaid() || ! $locked->hasExpired()) {
                    return false;
                }

                $locked->update(['status' => 'expired']);

                $locked->tenantOrders()
                    ->where('status', TenantOrder::STATUS_UNPAID)
                    ->update(['status' => TenantOrder::STATUS_CANCELLED]);

                $locked->paymentAttempts()
                    ->where('status', PaymentAttempt::STATUS_PENDING)
                    ->update([
                        'status' => PaymentAttempt::STATUS_EXPIRED,
                        'failure_reason' => 'Checkout expired before payment.',
                    ]);

                return true;
            });

            if ($didExpire) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function decide(?PaymentAttempt $attempt, array $payload): string
    {
        if ($attempt === null) {
            return 'unknown_order';
        }

        $grossAmount = (int) round((float) ($payload['gross_amount'] ?? 0));

        if ($grossAmount !== $attempt->amount) {
            $attempt->update([
                'failure_reason' => "Amount mismatch: gateway reported {$grossAmount}, expected {$attempt->amount}.",
            ]);

            return 'amount_mismatch';
        }

        $transactionStatus = (string) ($payload['transaction_status'] ?? '');
        $fraudStatus = isset($payload['fraud_status']) ? (string) $payload['fraud_status'] : null;

        return match ($transactionStatus) {
            'pending' => 'pending',
            'settlement', 'capture' => $fraudStatus === null || $fraudStatus === 'accept'
                ? $this->markPaid($attempt)
                : 'awaiting_fraud_review',
            'deny' => $this->markAttempt($attempt, PaymentAttempt::STATUS_DENIED, 'Payment denied by the gateway.', 'denied'),
            'cancel' => $this->markAttempt($attempt, PaymentAttempt::STATUS_CANCELLED, 'Payment cancelled.', 'cancelled'),
            'expire' => $this->markAttempt($attempt, PaymentAttempt::STATUS_EXPIRED, 'Payment expired.', 'expired'),
            'failure' => $this->markAttempt($attempt, PaymentAttempt::STATUS_FAILED, 'Payment failed.', 'failed'),
            default => 'ignored',
        };
    }

    /**
     * Confirm exactly one paid checkout and split it into settled tenant orders.
     * The row lock plus the monotonic status is what makes duplicate and
     * simultaneous notifications harmless.
     */
    private function markPaid(PaymentAttempt $attempt): string
    {
        return DB::transaction(function () use ($attempt): string {
            $lockedAttempt = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $checkout = Checkout::query()->whereKey($lockedAttempt->checkout_id)->lockForUpdate()->firstOrFail();

            if ($checkout->status === 'paid') {
                $lockedAttempt->update([
                    'status' => PaymentAttempt::STATUS_CANCELLED,
                    'failure_reason' => 'Duplicate payment after the checkout was already paid.',
                ]);

                return 'already_paid';
            }

            if ($checkout->status !== 'unpaid' || $checkout->hasExpired()) {
                $lockedAttempt->update([
                    'status' => PaymentAttempt::STATUS_CANCELLED,
                    'failure_reason' => 'Payment arrived after the checkout closed.',
                ]);

                return 'late_payment';
            }

            $lockedAttempt->update([
                'status' => PaymentAttempt::STATUS_SETTLED,
                'settled_at' => now(),
                'failure_reason' => null,
            ]);

            $checkout->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $feePercent = (int) config('kiosk.platform_fee_percent', 20);

            $unpaidOrders = $checkout->tenantOrders()
                ->where('status', TenantOrder::STATUS_UNPAID)
                ->get();

            foreach ($unpaidOrders as $tenantOrder) {
                // Whole rupiah, floor the fee so tenant + fee always equals the total.
                $platformFee = intdiv($tenantOrder->total_amount * $feePercent, 100);

                $tenantOrder->update([
                    'status' => TenantOrder::STATUS_PAID,
                    'paid_at' => now(),
                    'platform_fee_amount' => $platformFee,
                    'tenant_share_amount' => $tenantOrder->total_amount - $platformFee,
                ]);
            }

            return 'paid';
        });
    }

    private function markAttempt(PaymentAttempt $attempt, string $status, string $reason, string $outcome): string
    {
        return DB::transaction(function () use ($attempt, $status, $reason, $outcome): string {
            $locked = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

            if ($locked === null) {
                return 'ignored';
            }

            if ($locked->status === PaymentAttempt::STATUS_SETTLED) {
                return 'already_settled';
            }

            // Never rewrite an attempt that already reached a terminal state. A delayed
            // "expire" for an attempt that was cancelled must not become "expired".
            if (in_array($locked->status, [
                PaymentAttempt::STATUS_EXPIRED,
                PaymentAttempt::STATUS_DENIED,
                PaymentAttempt::STATUS_CANCELLED,
                PaymentAttempt::STATUS_FAILED,
            ], true)) {
                return 'ignored';
            }

            $locked->update([
                'status' => $status,
                'failure_reason' => $reason,
            ]);

            if ($status === PaymentAttempt::STATUS_EXPIRED) {
                $this->expireCheckoutIfDue((int) $locked->checkout_id);
            }

            return $outcome;
        });
    }

    /**
     * Close the checkout only if it is still unpaid and its window has closed.
     * Runs under a row lock so a concurrent successful payment always wins.
     */
    private function expireCheckoutIfDue(int $checkoutId): void
    {
        DB::transaction(function () use ($checkoutId): void {
            $checkout = Checkout::query()->whereKey($checkoutId)->lockForUpdate()->first();

            if ($checkout === null || ! $checkout->isUnpaid() || ! $checkout->hasExpired()) {
                return;
            }

            $checkout->update(['status' => 'expired']);
            $checkout->tenantOrders()
                ->where('status', TenantOrder::STATUS_UNPAID)
                ->update(['status' => TenantOrder::STATUS_CANCELLED]);
        });
    }

    private function gatewayOrderId(Checkout $checkout, int $attemptNo): string
    {
        $reference = str_replace('-', '', $checkout->reference);

        return 'GMFS-'.$reference.'-'.$attemptNo;
    }
}
