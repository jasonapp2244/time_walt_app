<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $supportedCurrencies = array_keys(config('services.stripe.supported_currencies', ['usd' => 'USD', 'eur' => 'EUR', 'gbp' => 'GBP']));

        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['required', 'string', 'size:3', Rule::in($supportedCurrencies)],
            'title' => ['nullable', 'string', 'max:255'],
            'hold_period_type' => ['required', 'string', Rule::in(['custom'])],
            'hold_start_at' => ['required', 'date', 'after_or_equal:today'],
            'hold_end_at' => ['required', 'date', 'after:hold_start_at'],
        ];
    }

    public function messages(): array
    {
        $supportedCurrencies = array_keys(config('services.stripe.supported_currencies', ['usd' => 'USD', 'eur' => 'EUR', 'gbp' => 'GBP']));
        $currencyList = implode(', ', array_map('strtoupper', $supportedCurrencies));

        return [
            'amount.required' => 'Amount is required.',
            'amount.min' => 'Minimum amount is $1.00.',
            'amount.numeric' => 'Amount must be a valid number.',
            'currency.required' => 'Currency is required.',
            'currency.in' => "Currency must be one of: {$currencyList}.",
            'title.max' => 'Title must not exceed 255 characters.',
            'hold_period_type.required' => 'Hold period type is required.',
            'hold_period_type.in' => 'Hold period type must be "custom".',
            'hold_start_at.required' => 'Hold start date is required.',
            'hold_start_at.date' => 'Hold start date must be a valid date.',
            'hold_start_at.after_or_equal' => 'Hold start date must be today or a future date.',
            'hold_end_at.required' => 'Hold end date is required.',
            'hold_end_at.date' => 'Hold end date must be a valid date.',
            'hold_end_at.after' => 'Hold end date must be after start date.',
        ];
    }
}
