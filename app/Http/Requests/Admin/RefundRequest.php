<?php

namespace App\Http\Requests\Admin;

use App\Models\Refund;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The admin's refund form. The amount may be the whole deposit or a part of
 * it; RefundService checks it against the real maximum under a row lock.
 */
class RefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'regex:/^\d{1,7}(\.\d{1,2})?$/', 'gte:0.01'],
            'request_token' => ['required', 'uuid'],
            'reason_code' => ['required', 'string', Rule::in(array_keys(Refund::REASONS))],
            'reason_note' => ['required', 'string', 'min:5', 'max:500'],
            'password' => ['required', 'string', 'current_password:web'],
            'confirm' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter the amount in dollars with at most 2 decimals, for example 25.50.',
            'amount.gte' => 'Enter an amount of at least $0.01.',
            'request_token.*' => 'The refund form expired. Close the popup, open it again and retry.',
            'password.current_password' => 'The admin password is incorrect. No refund was made.',
            'confirm.accepted' => 'Tick the confirmation box to refund this deposit.',
            'reason_note.min' => 'Write a short note (at least 5 characters) explaining the refund.',
        ];
    }
}
