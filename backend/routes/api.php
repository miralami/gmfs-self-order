<?php

use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\PaymentNotificationController;
use App\Http\Controllers\Api\V1\SettlementController;
use App\Http\Controllers\Api\V1\TenantOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public kiosk: catalog, quote, checkout, payment status.
    Route::get('/catalog/tenants', [CatalogController::class, 'tenants']);
    Route::get('/catalog/tenants/{tenant}/products', [CatalogController::class, 'products'])
        ->whereNumber('tenant');
    Route::post('/cart/quote', [CartController::class, 'quote']);

    Route::post('/checkouts', [CheckoutController::class, 'store']);
    Route::get('/checkouts/{reference}', [CheckoutController::class, 'show']);
    Route::post('/checkouts/{reference}/payment-attempts', [CheckoutController::class, 'retry']);
    Route::get('/checkouts/{reference}/qris', [CheckoutController::class, 'qris']);

    // Midtrans HTTP notification. Public by design; authenticity comes from the signature.
    Route::post('/payments/midtrans/notification', PaymentNotificationController::class);

    Route::middleware('api.token')->group(function () {
        Route::get('/tenant/orders', [TenantOrderController::class, 'index']);
        Route::get('/tenant/orders/{tenantOrder}', [TenantOrderController::class, 'show'])
            ->whereNumber('tenantOrder');
        Route::post('/tenant/orders/{tenantOrder}/queue-preparation', [TenantOrderController::class, 'queuePreparation'])
            ->whereNumber('tenantOrder');
        Route::get('/operator/settlements', [SettlementController::class, 'index']);
    });
});
