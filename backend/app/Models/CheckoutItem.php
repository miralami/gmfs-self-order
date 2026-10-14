<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'checkout_id',
        'tenant_id',
        'outlet_id',
        'product_id',
        'product_name',
        'unit_price_cents',
        'quantity',
        'line_total_cents',
    ];

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }
}
