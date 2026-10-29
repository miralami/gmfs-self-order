<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('gateway', 30)->default('midtrans');
            $table->string('payment_method', 20)->default('qris');
            // order_id sent to Midtrans. Unique, so a retried charge can never create
            // a second charge for the same logical attempt.
            $table->string('gateway_order_id', 60)->unique();
            $table->string('status', 20)->default('pending'); // pending|settled|denied|expired|cancelled|failed
            $table->unsignedInteger('amount');
            $table->string('transaction_id')->nullable();
            $table->text('qr_string')->nullable();
            $table->string('qr_image_url', 2048)->nullable();
            $table->json('gateway_response')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['checkout_id', 'attempt_no']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
