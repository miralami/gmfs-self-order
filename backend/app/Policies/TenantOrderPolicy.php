<?php

namespace App\Policies;

use App\Models\TenantOrder;
use App\Models\User;

class TenantOrderPolicy
{
    public function view(User $user, TenantOrder $tenantOrder): bool
    {
        return $user->isOperator() || ($user->isTenantManager() && (int) $user->tenant_id === (int) $tenantOrder->tenant_id);
    }

    public function queuePreparation(User $user, TenantOrder $tenantOrder): bool
    {
        return $user->isTenantManager() && (int) $user->tenant_id === (int) $tenantOrder->tenant_id;
    }
}
