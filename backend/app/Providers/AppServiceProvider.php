<?php

namespace App\Providers;

use App\Models\TenantOrder;
use App\Policies\TenantOrderPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(TenantOrder::class, TenantOrderPolicy::class);
    }
}
