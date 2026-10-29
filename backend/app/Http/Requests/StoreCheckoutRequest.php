<?php

namespace App\Http\Requests;

class StoreCheckoutRequest extends CartQuoteRequest
{
    /**
     * The client sends the cart only. Prices, totals and tenant identity come
     * from the server, never from the request body.
     */
    public function rules(): array
    {
        return parent::rules();
    }
}
