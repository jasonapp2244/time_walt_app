<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
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
            'email' => ['required_without:phone', 'string', 'email'],
            'phone' => ['required_without:email', 'string'],
            'otp_code' => ['required', 'string', 'size:4'],
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
            'email.required_without' => 'Email or phone is required.',
            'phone.required_without' => 'Email or phone is required.',
            'otp_code.required' => 'OTP code is required.',
            'otp_code.size' => 'OTP code must be 4 digits.',
        ];
    }
}

