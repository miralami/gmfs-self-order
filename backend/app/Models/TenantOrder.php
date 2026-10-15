<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'checkout_id',
        'tenant_id',
        'outlet_id',
        'total_cents',
        'status',
        'payment_confirmed_at',
        'preparation_queued_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_confirmed_at' => 'datetime',
            'preparation_queued_at' => 'datetime',
        ];
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
