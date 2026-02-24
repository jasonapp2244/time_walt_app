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
        // Check if this is a social login request
        $isSocialLogin = $this->filled('provider') && $this->filled('provider_id');

        return [
            // Email/phone required for all login types
            'email' => ['required_without:phone', 'string', 'email'],
            'phone' => ['required_without:email', 'string'],

            // Password only required for regular login (not social login)
            'password' => [$isSocialLogin ? 'nullable' : 'required', 'string'],

            // OTP for 2FA
            'otp_code' => ['nullable', 'string', 'size:4'],

            // Device info
            'device_id' => ['nullable', 'string'],
            'device_type' => ['nullable', 'string', 'in:ios,android,web'],
            'fcm_token' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string'],
            'language' => ['nullable', 'string'],

            // Social login fields
            'provider' => ['nullable', 'string', 'in:google,apple,facebook'],
            'provider_token' => [
                $isSocialLogin ? 'required' : 'nullable',
                'string',
            ],
            'provider_id' => [
                $isSocialLogin ? 'required' : 'nullable',
                'string',
            ],
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
            'password.required' => 'Password is required for regular login.',
            'otp_code.size' => 'OTP code must be 4 digits.',
            'provider_token.required' => 'Provider token is required for social login.',
            'provider_id.required' => 'Provider ID is required for social login.',
        ];
    }
}
