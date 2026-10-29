<?php

namespace App\Console\Commands;

use App\Services\PaymentService;
use Illuminate\Console\Command;

class ExpireCheckouts extends Command
{
    protected $signature = 'checkouts:expire {--limit=200 : Maximum checkouts to expire in one run}';

    protected $description = 'Expire unpaid checkouts whose payment window has closed';

    public function handle(PaymentService $paymentService): int
    {
        $expired = $paymentService->expireStaleCheckouts((int) $this->option('limit'));

        $this->info("Expired {$expired} checkout(s).");

        return self::SUCCESS;
    }
}
