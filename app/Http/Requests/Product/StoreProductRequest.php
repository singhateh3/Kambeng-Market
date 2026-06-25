<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only farmers can create products
        return $this->user() && $this->user()->isFarmer();
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'variety' => 'nullable|string|max:100',
            'quantity' => 'required|numeric|min:0.01',
            'unit' => 'required|in:kg,bunch,pile,bag',
            'price' => 'required|numeric|min:0.01',
            'harvest_date' => 'required|date|before_or_equal:today',
            'expiry_date' => 'required|date|after:harvest_date',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'image|max:5120', // 5MB max
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Product name is required',
            'category.required' => 'Product category is required',
            'variety.max' => 'Variety cannot exceed 100 characters',
            'quantity.required' => 'Quantity is required',
            'quantity.min' => 'Quantity must be greater than 0',
            'unit.required' => 'Unit of measurement is required',
            'unit.in' => 'Unit must be kg, bunch, pile, or bag',
            'price.required' => 'Price is required',
            'price.min' => 'Price must be greater than 0',
            'harvest_date.required' => 'Harvest date is required',
            'harvest_date.before_or_equal' => 'Harvest date cannot be in the future',
            'expiry_date.required' => 'Expiry date is required',
            'expiry_date.after' => 'Expiry date must be after harvest date',
            'photos.max' => 'Maximum 5 photos allowed',
            'photos.*.image' => 'Each file must be an image',
            'photos.*.max' => 'Each image must be less than 5MB',
        ];
    }
}