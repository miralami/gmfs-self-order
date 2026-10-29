<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettlementController extends Controller
{
    public function index(Request $request, SettlementService $settlementService): JsonResponse
    {
        abort_unless($request->user()->isOperator(), 403, 'Only the operator can view settlements.');

        return response()->json([
            'data' => $settlementService->summary(
                $request->query('from'),
                $request->query('to'),
            ),
        ]);
    }
}
