<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartQuoteRequest;
use App\Services\CartService;

class CartController extends Controller
{
    public function quote(CartQuoteRequest $request, CartService $cartService)
    {
        return response()->json([
            'data' => $cartService->quote($request->validated('items')),
        ]);
    }
}
