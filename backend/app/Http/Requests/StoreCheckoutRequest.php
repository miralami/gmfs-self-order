<?php

namespace App\Http\Requests;

class StoreCheckoutRequest extends CartQuoteRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'payment_method' => ['required', 'in:mock_card'],
        ]);
    }
}
