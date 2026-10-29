<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            // Short code shown to the customer and the tenant (for example "GMFS-7K2QX9").
            $table->string('public_code', 20)->unique();
            $table->unsignedInteger('total_amount');
            // The 80/20 split is fixed when the payment is confirmed, not when it is displayed.
            $table->unsignedInteger('tenant_share_amount')->nullable();
            $table->unsignedInteger('platform_fee_amount')->nullable();
            $table->string('status', 20)->default('unpaid'); // unpaid|paid|queued|cancelled
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamps();

            // One tenant order per tenant per checkout. This is the structural guarantee
            // that a duplicated payment notification can never split a checkout twice.
            $table->unique(['checkout_id', 'tenant_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_orders');
    }
};
