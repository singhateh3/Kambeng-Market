<?php

// app/Http/Requests/Product/UpdateProductRequest.php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');
        return $this->user() && $this->user()->id === $product->farmer_id;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'variety' => 'nullable|string|max:100',
            'category' => 'sometimes|string|max:100',
            'quantity' => 'sometimes|numeric|min:0.01',
            'unit' => ['sometimes', Rule::in(['kg', 'bunch', 'pile', 'bag'])],
            'price' => 'sometimes|numeric|min:0.01',
            'harvest_date' => 'sometimes|date|before_or_equal:today',
            'expiry_date' => 'sometimes|date|after:harvest_date',
            'description' => 'nullable|string|max:1000',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'image|max:5120',
            'remove_photos' => 'nullable|array',
            'remove_photos.*' => 'string',
        ];
    }
}