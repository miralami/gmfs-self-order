<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\PaymentStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckoutRequest;
use App\Http\Resources\CheckoutResource;
use App\Models\Checkout;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function store(
        StoreCheckoutRequest $request,
        CheckoutService $checkoutService,
        PaymentService $paymentService,
    ): JsonResponse {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'The Idempotency-Key header is required for checkout creation.',
            ]);
        }

        if (strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'The Idempotency-Key header may not be longer than 100 characters.',
            ]);
        }

        try {
            ['checkout' => $checkout, 'replayed' => $replayed] = $checkoutService->create(
                $request->validated('items'),
                $idempotencyKey,
            );
        } catch (IdempotencyConflictException) {
            return response()->json([
                'message' => 'This Idempotency-Key was already used with a different cart.',
            ], 409);
        }

        $paymentError = null;

        if (! $replayed && $checkout->isUnpaid() && ! $checkout->hasExpired()) {
            if (! $paymentService->gatewayConfigured()) {
                $paymentError = 'Midtrans server key is not configured. Set MIDTRANS_SERVER_KEY before taking payments.';
            } else {
                try {
                    $paymentService->startAttempt($checkout);
                } catch (PaymentStateException|PaymentGatewayException $exception) {
                    $paymentError = $exception->getMessage();
                }
            }
        }

        $checkout->refresh()->load(['items', 'tenantOrders.tenant', 'paymentAttempts']);

        return response()->json([
            'data' => new CheckoutResource($checkout),
            'meta' => [
                'replayed' => $replayed,
                'payment_error' => $paymentError,
            ],
        ], $replayed ? 200 : 201);
    }

    public function show(string $reference): JsonResponse
    {
        $checkout = $this->findByReference($reference);

        return response()->json(['data' => new CheckoutResource($checkout)]);
    }

    public function retry(string $reference, PaymentService $paymentService): JsonResponse
    {
        $checkout = $this->findByReference($reference);

        try {
            $paymentService->startAttempt($checkout);
        } catch (PaymentStateException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (PaymentGatewayException $exception) {
            $checkout->refresh()->load(['items', 'tenantOrders.tenant', 'paymentAttempts']);

            return response()->json([
                'message' => 'Midtrans rejected the payment attempt: '.$exception->getMessage(),
                'data' => new CheckoutResource($checkout),
            ], 502);
        }

        $checkout->refresh()->load(['items', 'tenantOrders.tenant', 'paymentAttempts']);

        return response()->json(['data' => new CheckoutResource($checkout)]);
    }

    /**
     * Proxy the QR image from Midtrans so the browser never sees the gateway URL
     * and the kiosk works from a different origin.
     */
    public function qris(string $reference): Response|JsonResponse
    {
        $checkout = $this->findByReference($reference);
        $attempt = $checkout->paymentAttempts()->latest('attempt_no')->first();

        if ($attempt === null || $attempt->qr_image_url === null) {
            return response()->json(['message' => 'No QR image is available for this checkout.'], 404);
        }

        $response = Http::timeout(15)->get($attempt->qr_image_url);

        if ($response->failed()) {
            return response()->json(['message' => 'Could not fetch the QR image from Midtrans.'], 502);
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'image/png',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function findByReference(string $reference): Checkout
    {
        return Checkout::query()
            ->whereReference($reference)
            ->with(['items', 'tenantOrders.tenant', 'paymentAttempts'])
            ->firstOrFail();
    }
}
