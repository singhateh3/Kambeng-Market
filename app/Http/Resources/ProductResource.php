// app/Http/Resources/ProductResource.php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'quantity' => (float) $this->quantity,
            'unit' => $this->unit,
            'unit_display' => $this->getUnitDisplay(),
            'price' => (float) $this->price,
            'price_formatted' => 'GMD ' . number_format($this->price, 2),
            'harvest_date' => $this->harvest_date?->toISOString(),
            'harvest_date_display' => $this->harvest_date?->format('M d, Y'),
            'expiry_date' => $this->expiry_date?->toISOString(),
            'expiry_date_display' => $this->expiry_date?->format('M d, Y'),
            'photos' => $this->photos ? array_map(function ($photo) {
                return asset($photo);
            }, $this->photos) : [],
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'is_available' => $this->isAvailable(),
            'created_at' => $this->created_at?->toISOString(),
            'farmer' => $this->whenLoaded('farmer', function () {
                return new UserResource($this->farmer);
            }),
            'orders_count' => $this->whenCounted('orders'),
            'average_rating' => $this->when(isset($this->average_rating), $this->average_rating),
        ];
    }

    protected function getUnitDisplay(): string
    {
        return match ($this->unit) {
            'kg' => 'Kilogram',
            'bunch' => 'Bunch',
            'pile' => 'Pile',
            'bag' => 'Bag',
            default => $this->unit,
        };
    }

    protected function getStatusLabel(): string
    {
        return match ($this->status) {
            'active' => 'Available',
            'sold' => 'Sold Out',
            default => ucfirst($this->status),
        };
    }
}