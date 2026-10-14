<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checkout extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'total_cents',
        'status',
        'payment_status',
        'payment_reference',
        'attempt_count',
        'last_error',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CheckoutItem::class);
    }

    public function tenantOrders(): HasMany
    {
        return $this->hasMany(TenantOrder::class);
    }

    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }
}
