<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
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
        $userId = $this->user()->id;

        return [
            'full_name' => ['nullable', 'sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'sometimes', 'string', 'max:20', 'unique:users,phone,' . $userId],
            'profile_image' => ['nullable', 'sometimes', 'file', 'image', 'mimes:jpeg,jpg,png,gif', 'max:2048'],
            'timezone' => ['nullable', 'sometimes', 'string', 'max:100'],
            'language' => ['nullable', 'sometimes', 'string', 'max:10'],
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
            'full_name.string' => 'Full name must be a string.',
            'phone.unique' => 'This phone number is already taken.',
            'profile_image.image' => 'Profile image must be an image file.',
            'profile_image.mimes' => 'Profile image must be jpeg, jpg, png, or gif.',
            'profile_image.max' => 'Profile image must not exceed 2MB.',
        ];
    }
}

