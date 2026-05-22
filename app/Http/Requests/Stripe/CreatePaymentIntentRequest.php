<?php

namespace App\Http\Requests\Stripe;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentIntentRequest extends FormRequest
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
        // Get supported currencies from config (use keys as currency codes)
        $supportedCurrencies = array_keys(config('services.stripe.supported_currencies', ['usd' => 'USD', 'eur' => 'EUR', 'gbp' => 'GBP']));

        return [
            'amount' => ['required', 'numeric', 'min:1'], // amount in dollars (minimum $1.00)
            'currency' => ['required', 'string', 'size:3', Rule::in($supportedCurrencies)],
            'title' => ['nullable', 'string', 'max:255'], // Optional title/description for the payment hold
            'hold_period_type' => ['required', 'string', Rule::in(['custom'])], // Only custom hold period allowed
            'hold_start_at' => ['required', 'date', 'after_or_equal:today'], // Date or datetime
            'hold_end_at' => ['required', 'date', 'after:hold_start_at'], // Date or datetime
            'hold_hours' => ['nullable', 'integer', 'min:0', 'max:23'], // Hours component
            'hold_minutes' => ['nullable', 'integer', 'min:0', 'max:59'], // Minutes component
            'return_url' => ['nullable', 'url', 'max:500'], // Optional - will use config default if not provided
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate that dates are valid and end is after start (supports datetime precision)
            if ($this->hold_start_at && $this->hold_end_at) {
                try {
                    $startDate = \Carbon\Carbon::parse($this->hold_start_at);
                    $endDate = \Carbon\Carbon::parse($this->hold_end_at);

                    if ($endDate->lte($startDate)) {
                        $validator->errors()->add('hold_end_at', 'Hold end must be after hold start.');
                    }
                } catch (\Exception $e) {
                    $validator->errors()->add('hold_start_at', 'Invalid date/time format.');
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
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
            'title.string' => 'Title must be a valid text.',
            'title.max' => 'Title must not exceed 255 characters.',
            'return_url.url' => 'Return URL must be a valid URL.',
            'hold_period_type.required' => 'Hold period type is required.',
            'hold_period_type.in' => 'Hold period type must be "custom".',
            'hold_start_at.required' => 'Hold start date is required.',
            'hold_start_at.date' => 'Hold start date must be a valid date.',
            'hold_start_at.after_or_equal' => 'Hold start date must be today or a future date.',
            'hold_end_at.required' => 'Hold end date is required.',
            'hold_end_at.date' => 'Hold end date must be a valid date.',
            'hold_end_at.after' => 'Hold end date must be after start date.',
            'hold_hours.integer' => 'Hold hours must be a valid number.',
            'hold_hours.min' => 'Hold hours cannot be negative.',
            'hold_hours.max' => 'Hold hours cannot exceed 23.',
            'hold_minutes.integer' => 'Hold minutes must be a valid number.',
            'hold_minutes.min' => 'Hold minutes cannot be negative.',
            'hold_minutes.max' => 'Hold minutes cannot exceed 59.',
        ];
    }
}
