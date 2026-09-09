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
        return [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'location' => 'sometimes|string|max:255',
            // gif/bmp/svg are valid per Laravel's `image` rule but not per
            // the product spec (JPEG/PNG/WebP only) — `mimes` narrows it.
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120', // 5MB
            // Explicit removal — same shape as UpdateProductRequest's
            // `remove_photos`. Ignored when a new `avatar` file is present
            // in the same request (AuthController::updateProfile treats a
            // new upload as taking priority).
            'remove_avatar' => 'nullable|boolean',
            'bio' => 'nullable|string|max:500',
            // required_if fires only when this same request also carries
            // `role=farmer` — see CompleteProfile.jsx (frontend), the only
            // caller that ever sends `role`. An existing farmer editing just
            // their bio via the normal Profile page never sends `role`, so
            // this stays optional for them exactly as before. Mirrors
            // RegisterUserRequest's identical required_if for the same pair.
            'farm_name' => 'nullable|required_if:role,farmer|string|max:255',
            'farm_location' => 'nullable|required_if:role,farmer|string|max:255',
            // Self-service role changes are restricted to buyer -> farmer —
            // enforced in AuthController::updateProfile(), not here; see the
            // comment there for why.
            'role' => ['sometimes', Rule::in(['buyer', 'farmer'])],
            'current_password' => 'required_with:new_password|string|current_password',
            'new_password' => 'nullable|string|min:8|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.image' => 'Avatar must be an image file',
            'avatar.mimes' => 'Avatar must be a JPEG, PNG, or WebP image',
            'avatar.max' => 'Avatar must be less than 5MB',
            'farm_name.required_if' => 'Farm name is required for farmers',
            'farm_location.required_if' => 'Farm location is required for farmers',
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