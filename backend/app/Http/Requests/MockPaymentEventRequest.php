<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MockPaymentEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string'],
            'status' => ['required', 'in:paid,failed'],
            'reason' => ['nullable', 'string'],
            'payment_reference' => ['nullable', 'string'],
        ];
    }
}
