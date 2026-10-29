<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAttempt extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_DENIED = 'denied';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'checkout_id',
        'attempt_no',
        'gateway',
        'payment_method',
        'gateway_order_id',
        'status',
        'amount',
        'transaction_id',
        'qr_string',
        'qr_image_url',
        'gateway_response',
        'failure_reason',
        'expires_at',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'amount' => 'integer',
            'gateway_response' => 'array',
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(PaymentNotification::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
