<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_intent_id' => ['required', 'string', 'starts_with:pi_'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_intent_id.required' => 'Payment intent ID is required.',
            'payment_intent_id.string' => 'Payment intent ID must be a string.',
            'payment_intent_id.starts_with' => 'Invalid payment intent ID format.',
        ];
    }
}
