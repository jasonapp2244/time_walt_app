<?php

namespace App\Http\Requests\Bank;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dob' => ['required', 'date', 'before:today'],
            'account_number' => ['required', 'string', 'min:5', 'max:34'],
            'bank_name' => ['required', 'string', 'max:100'],
            'routing_number' => ['nullable', 'string', 'size:9'],
            'iban' => ['nullable', 'string', 'min:15', 'max:34'],
            'account_type' => ['required', 'in:savings,checking,current'],
            'country' => ['required', 'string', 'size:2'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }
}
