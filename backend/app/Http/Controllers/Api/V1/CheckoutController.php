<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RetryCheckoutRequest;
use App\Http\Requests\StoreCheckoutRequest;
use App\Models\Checkout;
use App\Services\CheckoutService;

class CheckoutController extends Controller
{
    public function store(StoreCheckoutRequest $request, CheckoutService $checkoutService)
    {
        $checkout = $checkoutService->createCheckout($request->validated('items'));

        return response()->json([
            'data' => $checkout,
        ], 201);
    }

    public function retry(RetryCheckoutRequest $request, Checkout $checkout, CheckoutService $checkoutService)
    {
        if ($checkout->payment_status !== 'failed') {
            return response()->json(['message' => 'Only failed checkouts can be retried.'], 422);
        }

        return response()->json([
            'data' => $checkoutService->retry($checkout),
        ]);
    }
}
