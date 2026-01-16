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
        return [
            'amount' => ['required', 'integer', 'min:100'], // minimum $1.00 in cents
            'currency' => ['required', 'string', 'size:3', 'in:usd,eur,gbp'],
            'hold_period_type' => ['nullable', 'string', Rule::in(['1_month', '2_months', '6_months', '1_year', 'custom'])],
            'hold_start_at' => ['nullable', 'date', 'after_or_equal:today'],
            'hold_end_at' => ['nullable', 'date', 'after:hold_start_at'],
            'hold_days' => ['nullable', 'integer', 'min:30'],
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
                    $startDate = \Carbon\Carbon::parse($this->hold_start_at);
                    $endDate = \Carbon\Carbon::parse($this->hold_end_at);
                    $days = $startDate->diffInDays($endDate);

                    if ($days < 30) {
                        $validator->errors()->add('hold_end_at', 'Hold period must be at least 30 days.');
                    }
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
        return [
            'amount.required' => 'Amount is required.',
            'amount.min' => 'Minimum amount is $1.00.',
            'currency.required' => 'Currency is required.',
            'hold_period_type.in' => 'Invalid hold period type.',
            'hold_end_at.after' => 'End date must be after start date.',
            'hold_days.min' => 'Minimum hold period is 30 days.',
        ];
    }
}
