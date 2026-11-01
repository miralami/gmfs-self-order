<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Checkout extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'idempotency_key',
        'request_hash',
        'total_amount',
        'status',
        'expires_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CheckoutItem::class);
    }

    /**
     * Look a checkout up by its public reference.
     *
     * `reference` is a native `uuid` column, so PostgreSQL raises SQLSTATE 22P02
     * for a malformed value instead of returning no rows (SQLite would silently
     * return nothing). Reject it in PHP so a bad URL or CLI argument stays a
     * clean 404 instead of becoming a 500 on the database the thesis targets.
     */
    public function scopeWhereReference(Builder $query, string $reference): Builder
    {
        if (! Str::isUuid($reference)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('reference', $reference);
    }

    public function tenantOrders(): HasMany
    {
        return $this->hasMany(TenantOrder::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function latestAttempt(): ?PaymentAttempt
    {
        return $this->paymentAttempts()->latest('attempt_no')->first();
    }

    public function isUnpaid(): bool
    {
        return $this->status === 'unpaid';
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
