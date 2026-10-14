<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MockPaymentEventRequest;
use App\Models\Checkout;
use App\Services\MockPaymentService;
use Illuminate\Support\Str;

class MockPaymentController extends Controller
{
    public function complete(Checkout $checkout, MockPaymentEventRequest $request, MockPaymentService $mockPaymentService)
    {
        $payload = $request->validated();
        $event = $mockPaymentService->applyEvent(
            $checkout,
            $payload['event_id'],
            $payload['status'],
            $payload,
        );

        return response()->json([
            'data' => $event,
            'checkout' => $checkout->fresh(['tenantOrders']),
        ]);
    }

    public function simulate(Checkout $checkout, MockPaymentService $mockPaymentService)
    {
        $status = request()->input('status', 'paid');
        abort_unless(in_array($status, ['paid', 'failed'], true), 422, 'Invalid status.');

        $event = $mockPaymentService->applyEvent(
            $checkout,
            (string) Str::uuid(),
            $status,
            [
                'payment_reference' => 'mock-'.Str::upper(Str::random(8)),
                'reason' => $status === 'failed' ? 'Simulated failure' : null,
            ],
        );

        return response()->json([
            'data' => $event,
            'checkout' => $checkout->fresh(['tenantOrders']),
        ]);
    }
}
