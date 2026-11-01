<?php

namespace App\Console\Commands;

use App\Models\Checkout;
use App\Models\PaymentAttempt;
use Illuminate\Console\Command;

/**
 * Offline demo harness (CLI only).
 *
 * The application has exactly one payment adapter: Midtrans. This command does
 * not talk to Midtrans and is not reachable over HTTP. It exists so the kiosk,
 * the tenant dashboard and the settlement report can be demonstrated when the
 * examiner's machine has no sandbox key and no public HTTPS tunnel. Pair it with
 * `payments:simulate`, which still goes through the real signature check and
 * state machine.
 */
class DemoCharge extends Command
{
    protected $signature = 'payments:demo-charge {reference : Checkout reference (UUID)}';

    protected $description = 'Create a pending demo payment attempt without calling Midtrans (offline demos only)';

    public function handle(): int
    {
        $checkout = Checkout::query()->whereReference((string) $this->argument('reference'))->first();

        if ($checkout === null) {
            $this->error('Checkout not found.');

            return self::FAILURE;
        }

        if (! $checkout->isUnpaid() || $checkout->hasExpired()) {
            $this->error('This checkout is not awaiting payment or has expired.');

            return self::FAILURE;
        }

        $attemptNo = ((int) $checkout->paymentAttempts()->max('attempt_no')) + 1;

        $attempt = $checkout->paymentAttempts()->create([
            'attempt_no' => $attemptNo,
            'gateway' => 'midtrans',
            'payment_method' => 'qris',
            'gateway_order_id' => 'GMFS-'.str_replace('-', '', $checkout->reference).'-'.$attemptNo,
            'status' => PaymentAttempt::STATUS_PENDING,
            'amount' => $checkout->total_amount,
            'qr_string' => 'DEMO-'.$checkout->reference,
            'expires_at' => $checkout->expires_at,
        ]);

        $this->info('Demo attempt created (no Midtrans call).');
        $this->line('order_id: '.$attempt->gateway_order_id);
        $this->line('amount: '.$attempt->amount);
        $this->line('Next: php artisan payments:simulate '.$checkout->reference);

        return self::SUCCESS;
    }
}
