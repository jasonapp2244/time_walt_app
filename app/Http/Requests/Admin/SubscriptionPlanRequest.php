<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriptionPlanRequest extends FormRequest
{
    /**
     * The route sits behind the admin middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'features' => ['nullable', 'string', 'max:2000'],
            // Stripe's minimum charge is 0.50 in USD, EUR and GBP.
            'price' => ['required', 'numeric', 'min:0.50', 'max:99999.99'],
            'currency' => ['required', Rule::in(array_keys(config('services.stripe.supported_currencies')))],
            'interval' => ['required', Rule::in(['month', 'year'])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Validated data shaped for the model: one feature per textarea line.
     */
    public function planAttributes(): array
    {
        $data = $this->validated();

        $features = collect(preg_split('/\r\n|\r|\n/', (string) ($data['features'] ?? '')))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();

        return [
            'name' => trim($data['name']),
            'description' => isset($data['description']) ? trim($data['description']) : null,
            'features' => $features,
            'price' => $data['price'],
            'currency' => strtolower($data['currency']),
            'interval' => $data['interval'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
