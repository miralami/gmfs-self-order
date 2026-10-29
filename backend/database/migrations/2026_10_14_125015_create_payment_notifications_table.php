<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway_order_id', 60);
            $table->string('transaction_status', 30);
            $table->string('status_code', 10);
            $table->string('fraud_status', 30)->nullable();
            $table->string('signature_key', 200)->nullable();
            $table->boolean('signature_valid')->default(false);
            // What the handler decided to do with this delivery. Kept for the audit trail.
            $table->string('outcome', 40)->default('received');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // A replayed delivery of the same gateway event is stored exactly once.
            // The signature is part of the identity on purpose: a forged delivery with a
            // bad signature must not be able to occupy the slot and make the genuine
            // Midtrans retry look like a duplicate.
            $table->unique(['gateway_order_id', 'transaction_status', 'status_code', 'signature_key'], 'payment_notifications_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notifications');
    }
};
