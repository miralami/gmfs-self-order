<?php

namespace App\Policies;

use App\Models\TenantOrder;
use App\Models\User;

class TenantOrderPolicy
{
    public function view(User $user, TenantOrder $tenantOrder): bool
    {
        if ($user->isOperator()) {
            return true;
        }

        return $user->isTenantManager()
            && $user->tenant_id !== null
            && (int) $user->tenant_id === (int) $tenantOrder->tenant_id;
    }

    public function queuePreparation(User $user, TenantOrder $tenantOrder): bool
    {
        // Only the owning tenant may queue its own order. The operator has read
        // access to settlements, but does not operate a stall.
        return $user->isTenantManager()
            && $user->tenant_id !== null
            && (int) $user->tenant_id === (int) $tenantOrder->tenant_id;
    }
}
