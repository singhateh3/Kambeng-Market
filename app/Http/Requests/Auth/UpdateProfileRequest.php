<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'location' => 'sometimes|string|max:255',
            'avatar' => 'nullable|image|max:5120', // 5MB
            'bio' => 'nullable|string|max:500',
            'farm_name' => 'sometimes|string|max:255',
            'farm_location' => 'sometimes|string|max:255',
            'current_password' => 'required_with:new_password|string|current_password',
            'new_password' => 'nullable|string|min:8|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.image' => 'Avatar must be an image file',
            'avatar.max' => 'Avatar must be less than 5MB',
            'current_password.required_with' => 'Current password is required to change password',
            'current_password.current_password' => 'Current password is incorrect',
            'new_password.min' => 'New password must be at least 8 characters',
            'new_password.confirmed' => 'New password confirmation does not match',
        ];
    }

    public function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge([
                'phone' => preg_replace('/[^0-9+]/', '', $this->phone),
            ]);
        }
    }
}