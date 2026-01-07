<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'password' => ['required', 'string'],
            'otp_code' => ['nullable', 'string', 'size:4'],
            'device_id' => ['nullable', 'string'],
            'device_type' => ['nullable', 'string', 'in:ios,android,web'],
            'fcm_token' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string'],
            'language' => ['nullable', 'string'],
            'provider' => ['nullable', 'string', 'in:google,apple,facebook'],
            'provider_token' => ['nullable', 'string'],
            'provider_id' => ['nullable', 'string'],
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
            'password.required' => 'Password is required.',
            'otp_code.size' => 'OTP code must be 4 digits.',
        ];
    }
}

