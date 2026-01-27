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
            'hold_period_type' => ['nullable', 'string', Rule::in(['1_month', '2_months', '6_months', '1_year', 'custom'])],
            'hold_start_at' => ['nullable', 'date', 'after_or_equal:today'],
            'hold_end_at' => ['nullable', 'date', 'after:hold_start_at'],
            'hold_days' => ['nullable', 'integer', 'min:30'],
            'return_url' => ['nullable', 'url', 'max:500'], // Optional - will use config default if not provided
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // If custom period, start and end dates required
            if ($this->hold_period_type === 'custom') {
                if (! $this->hold_start_at || ! $this->hold_end_at) {
                    $validator->errors()->add('hold_period_type', 'Start and end dates are required for custom period.');
                }

                // Check minimum 30 days
                if ($this->hold_start_at && $this->hold_end_at) {
                    try {
                        $startDate = \Carbon\Carbon::parse($this->hold_start_at)->startOfDay();
                        $endDate = \Carbon\Carbon::parse($this->hold_end_at)->startOfDay();
                        $days = $startDate->diffInDays($endDate);

                        if ($days < 30) {
                            $validator->errors()->add('hold_end_at', 'Hold period must be at least 30 days.');
                        }
                    } catch (\Exception $e) {
                        $validator->errors()->add('hold_start_at', 'Invalid date format.');
                    }
                }
            }

            // Validate hold_start_at is today or future (for all hold types)
            if ($this->hold_start_at) {
                try {
                    $startDate = \Carbon\Carbon::parse($this->hold_start_at)->startOfDay();
                    $today = \Carbon\Carbon::today();

                    if ($startDate->lt($today)) {
                        $validator->errors()->add('hold_start_at', 'Hold start date must be today or a future date.');
                    }
                } catch (\Exception $e) {
                    // Date parsing error already handled by 'date' rule
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
            'return_url.required' => 'Return URL is required for checkout session.',
            'return_url.url' => 'Return URL must be a valid URL.',
            'hold_period_type.in' => 'Invalid hold period type.',
            'hold_start_at.date' => 'Hold start date must be a valid date.',
            'hold_start_at.after_or_equal' => 'Hold start date must be today or a future date.',
            'hold_end_at.date' => 'Hold end date must be a valid date.',
            'hold_end_at.after' => 'End date must be after start date.',
            'hold_days.min' => 'Minimum hold period is 30 days.',
        ];
    }
}
