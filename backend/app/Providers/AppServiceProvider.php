<?php

namespace App\Providers;

use App\Models\TenantOrder;
use App\Policies\TenantOrderPolicy;
use App\Services\MidtransGateway;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MidtransGateway::class, fn (): MidtransGateway => new MidtransGateway(
            (string) config('services.midtrans.server_key'),
            (string) config('services.midtrans.base_url'),
            (int) config('services.midtrans.timeout', 15),
            config('services.midtrans.ca_bundle'),
        ));
    }

    public function boot(): void
    {
        Gate::policy(TenantOrder::class, TenantOrderPolicy::class);
    }
}
