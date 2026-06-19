<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');
        return $this->user() && $this->user()->id === $product->farmer_id;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:active,sold',
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Status is required',
            'status.in' => 'Status must be active or sold',
        ];
    }
}