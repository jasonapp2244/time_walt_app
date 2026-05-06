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
            'routing_number' => ['nullable', 'required_if:country,US', 'string', 'size:9'],
            'iban' => ['nullable', 'required_unless:country,US', 'string', 'min:15', 'max:34'],
            'account_type' => ['required', 'in:savings,checking,current'],
            'country' => ['required', 'string', 'size:2', 'in:US,GB,CA,AU,DE,FR,IE,NL,AT,BE,ES,IT,PT,DK,FI,NO,SE,CH,NZ,SG,HK,JP'],
            'currency' => ['required', 'string', 'size:3', 'in:usd,eur,gbp,cad,aud,nzd,sgd,hkd,jpy,chf,dkk,nok,sek'],
        ];
    }

    public function messages(): array
    {
        return [
            'country.in' => 'The selected country is not supported for bank account payouts.',
            'currency.in' => 'The selected currency is not supported.',
            'routing_number.required_if' => 'Routing number is required for US bank accounts.',
            'iban.required_unless' => 'IBAN is required for non-US bank accounts.',
        ];
    }
}
