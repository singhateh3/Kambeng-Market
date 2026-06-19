<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Anyone can register
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'required|string|max:20',
            'location' => 'required|string|max:255',
            'role' => ['required', Rule::in(['farmer', 'buyer'])],
            'password' => 'required|string|min:8|confirmed',
            'farm_name' => 'required_if:role,farmer|string|max:255|nullable',
            'farm_location' => 'required_if:role,farmer|string|max:255|nullable',
            'bio' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Full name is required',
            'name.max' => 'Name cannot exceed 255 characters',
            'email.required' => 'Email address is required',
            'email.email' => 'Please enter a valid email address',
            'email.unique' => 'This email is already registered',
            'phone.required' => 'Phone number is required',
            'location.required' => 'Location is required',
            'role.required' => 'Please select a role (farmer or buyer)',
            'role.in' => 'Role must be either farmer or buyer',
            'password.required' => 'Password is required',
            'password.min' => 'Password must be at least 8 characters',
            'password.confirmed' => 'Password confirmation does not match',
            'farm_name.required_if' => 'Farm name is required for farmers',
            'farm_location.required_if' => 'Farm location is required for farmers',
        ];
    }

    public function prepareForValidation(): void
    {
        // Clean up phone number
        if ($this->has('phone')) {
            $this->merge([
                'phone' => preg_replace('/[^0-9+]/', '', $this->phone),
            ]);
        }
    }

    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);

        // Remove farm fields if not farmer
        if (isset($validated['role']) && $validated['role'] !== 'farmer') {
            unset($validated['farm_name'], $validated['farm_location']);
        }

        return $validated;
    }
}