<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantOrder extends Model
{
    use HasFactory;

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAID = 'paid';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'checkout_id',
        'tenant_id',
        'outlet_id',
        'public_code',
        'total_amount',
        'tenant_share_amount',
        'platform_fee_amount',
        'status',
        'paid_at',
        'queued_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'integer',
            'tenant_share_amount' => 'integer',
            'platform_fee_amount' => 'integer',
            'paid_at' => 'datetime',
            'queued_at' => 'datetime',
        ];
    }

    /**
     * Scope the query to what the given user is allowed to see.
     *
     * Tenant managers only ever see their own tenant. Operators may see every tenant,
     * but only through this explicit method: no endpoint builds an unscoped query.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isOperator()) {
            return $query;
        }

        if ($user->isTenantManager() && $user->tenant_id !== null) {
            return $query->where('tenant_id', $user->tenant_id);
        }

        // Fail closed: an unknown role sees nothing.
        return $query->whereRaw('1 = 0');
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
