<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SignupRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // Only unique if verified OR not deleted
                Rule::unique('users')->where(function ($query) {
                    return $query->where('is_verified', true)
                        ->where('status', '!=', 'deleted');
                }),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                // Only unique if verified OR not deleted (same logic as email)
                Rule::unique('users')->where(function ($query) {
                    return $query->where('is_verified', true)
                        ->where('status', '!=', 'deleted');
                }),
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:128',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#^()_+\-=\[\]{};:,.<>\/])[A-Za-z\d@$!%*?&#^()_+\-=\[\]{};:,.<>\/]{8,}$/',
            ],
            'provider' => ['nullable', 'string', 'in:google,apple,facebook'],
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
            'full_name.required' => 'Full name is required.',
            'email.required' => 'Email is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email is already registered.',
            'phone.required' => 'Phone number is required.',
            'phone.unique' => 'This phone number is already registered.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters long.',
            'password.max' => 'Password must not exceed 128 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character (@$!%*?&#^()_+-=[]{};:,.<>/).',
        ];
    }
}
