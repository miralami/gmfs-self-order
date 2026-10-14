<?php

use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\MockPaymentController;
use App\Http\Controllers\Api\V1\SettlementController;
use App\Http\Controllers\Api\V1\TenantOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/catalog/tenants', [CatalogController::class, 'tenants']);
    Route::get('/catalog/tenants/{tenant}/products', [CatalogController::class, 'products']);

    Route::post('/cart/quote', [CartController::class, 'quote']);

    Route::post('/checkouts', [CheckoutController::class, 'store']);
    Route::post('/checkouts/{checkout}/payments/mock/simulate', [MockPaymentController::class, 'simulate']);
    Route::post('/checkouts/{checkout}/payments/mock/event', [MockPaymentController::class, 'complete']);
    Route::post('/checkouts/{checkout}/retry', [CheckoutController::class, 'retry']);

    Route::middleware('api.token')->group(function () {
        Route::get('/tenant/orders', [TenantOrderController::class, 'index']);
        Route::post('/tenant/orders/{tenantOrder}/queue-preparation', [TenantOrderController::class, 'queuePreparation']);
        Route::get('/operator/settlements', [SettlementController::class, 'index']);
    });
});
