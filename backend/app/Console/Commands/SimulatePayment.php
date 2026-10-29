<?php

namespace App\Console\Commands;

use App\Models\Checkout;
use App\Services\MidtransGateway;
use App\Services\PaymentService;
use Illuminate\Console\Command;

/**
 * Examiner / offline demo helper.
 *
 * This does not bypass the payment pipeline: it builds a notification payload
 * shaped exactly like a Midtrans delivery, signs it with the configured server
 * key, and sends it through the same signature check and state machine that a
 * real webhook uses. Use it when there is no public HTTPS tunnel available.
 */
class SimulatePayment extends Command
{
    protected $signature = 'payments:simulate
        {reference : Checkout reference (UUID)}
        {--outcome=settlement : settlement, expire, deny or cancel}';

    protected $description = 'Send a signed, Midtrans-shaped notification for a checkout (offline demo)';

    public function handle(PaymentService $paymentService, MidtransGateway $gateway): int
    {
        $checkout = Checkout::query()->where('reference', $this->argument('reference'))->first();

        if ($checkout === null) {
            $this->error('Checkout not found.');

            return self::FAILURE;
        }

        $attempt = $checkout->paymentAttempts()->latest('attempt_no')->first();

        if ($attempt === null) {
            $this->error('This checkout has no payment attempt.');

            return self::FAILURE;
        }

        $outcome = (string) $this->option('outcome');
        $grossAmount = number_format($attempt->amount, 2, '.', '');
        $statusCode = $outcome === 'settlement' ? '200' : '202';

        $payload = [
            'transaction_id' => $attempt->transaction_id ?? 'simulated-'.$attempt->id,
            'order_id' => $attempt->gateway_order_id,
            'gross_amount' => $grossAmount,
            'payment_type' => 'qris',
            'transaction_status' => $outcome,
            'transaction_time' => now()->format('Y-m-d H:i:s'),
            'status_code' => $statusCode,
            'fraud_status' => $outcome === 'settlement' ? 'accept' : null,
            'status_message' => 'Simulated by payments:simulate',
        ];

        $payload['signature_key'] = $gateway->signatureKey($payload['order_id'], $statusCode, $grossAmount);

        $result = $paymentService->applyNotification($payload);

        $this->info("Notification outcome: {$result}");

        $checkout->refresh()->load('tenantOrders');
        $this->table(
            ['Tenant', 'Code', 'Total', 'Status'],
            $checkout->tenantOrders->map(fn ($order) => [
                $order->tenant_id,
                $order->public_code,
                $order->total_amount,
                $order->status,
            ])->all(),
        );

        return $result === 'invalid_signature' ? self::FAILURE : self::SUCCESS;
    }
}
