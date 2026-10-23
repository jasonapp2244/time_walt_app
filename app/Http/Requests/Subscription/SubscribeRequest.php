<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscribeRequest extends FormRequest
{
    /**
     * The route sits behind auth:sanctum and always subscribes the caller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only a plan the app is currently offering can be bought.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan_id' => [
                'required',
                'integer',
                Rule::exists('subscription_plans', 'id')
                    ->where('is_active', true)
                    ->whereNotNull('stripe_price_id')
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.required' => 'Please choose a plan.',
            'plan_id.exists' => 'This plan is no longer available.',
        ];
    }
}
