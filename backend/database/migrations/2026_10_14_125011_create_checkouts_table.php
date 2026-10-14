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
            $table->uuid('reference')->unique();
            $table->unsignedInteger('total_cents');
            $table->string('status')->default('awaiting_payment');
            $table->string('payment_status')->default('pending');
            $table->string('payment_reference')->nullable();
            $table->unsignedInteger('attempt_count')->default(1);
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};
