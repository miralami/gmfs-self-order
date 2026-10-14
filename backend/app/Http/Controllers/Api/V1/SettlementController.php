<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SettlementService;

class SettlementController extends Controller
{
    public function index(SettlementService $settlementService)
    {
        abort_unless(request()->user()->isOperator(), 403, 'Only operators can view settlement summaries.');

        return response()->json([
            'data' => $settlementService->summary(
                request()->query('from'),
                request()->query('to'),
            ),
        ]);
    }
}
