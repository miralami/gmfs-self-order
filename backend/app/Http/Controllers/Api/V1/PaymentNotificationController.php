<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentNotificationController extends Controller
{
    public function __invoke(Request $request, PaymentService $paymentService): JsonResponse
    {
        // Parse permissively: Midtrans adds fields over time and a strict DTO would break.
        $payload = $request->all();

        $outcome = $paymentService->applyNotification($payload);

        if ($outcome === 'invalid_signature') {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        // Every other outcome returns 200, so Midtrans does not retry a delivery
        // that has already been recorded and judged.
        return response()->json([
            'message' => 'OK',
            'outcome' => $outcome,
        ]);
    }
}
