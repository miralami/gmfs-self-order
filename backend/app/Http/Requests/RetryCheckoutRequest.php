<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetryCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'in:mock_card'],
        ];
    }
}
