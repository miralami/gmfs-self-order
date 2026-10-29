<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            // Public, unguessable identifier used by the kiosk and the receipt page.
            $table->uuid('reference')->unique();
            // Client intent key. One key per checkout intent; the unique index is the
            // durable guard against double taps and retries (see CheckoutService).
            $table->string('idempotency_key', 100)->unique();
            // Hash of the request body, so a reused key with a different cart is rejected.
            $table->string('request_hash', 64);
            $table->unsignedInteger('total_amount');
            $table->string('status', 20)->default('unpaid'); // unpaid|paid|expired|cancelled
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};
