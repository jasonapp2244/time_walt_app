<?php

namespace App\Http\Requests\Stripe;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawPayoutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Withdrawal amount is required.',
            'amount.numeric' => 'Amount must be a valid number.',
            'amount.min' => 'Minimum withdrawal amount is $1.00.',
        ];
    }
}
