<?php

namespace App\Console\Commands;

use App\Services\PaymentService;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--limit=50 : Maximum attempts to check}';

    protected $description = 'Ask Midtrans for the status of pending attempts that never received a notification';

    public function handle(PaymentService $paymentService): int
    {
        $result = $paymentService->reconcilePending((int) $this->option('limit'));

        $this->info(sprintf(
            'Checked %d attempt(s), updated %d, failed %d.',
            $result['checked'],
            $result['updated'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
