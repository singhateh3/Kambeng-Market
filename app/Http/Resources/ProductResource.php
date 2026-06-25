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
            'variety' => $this->variety,
            'quantity' => (float) $this->quantity,
            'unit' => $this->unit,
            'unit_display' => $this->getUnitDisplay(),
            'price' => (float) $this->price,
            'price_formatted' => 'GMD ' . number_format($this->price, 2),
            'harvest_date' => $this->harvest_date?->toISOString(),
            'harvest_date_display' => $this->harvest_date?->format('M d, Y'),
            'expiry_date' => $this->expiry_date?->toISOString(),
            'expiry_date_display' => $this->expiry_date?->format('M d, Y'),
            'photos' => $this->getPhotoUrls(),
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

    /**
     * Get photo URLs with proper asset handling
     */
    protected function getPhotoUrls(): array
    {
        if (empty($this->photos)) {
            return [];
        }

        // If photos is a string, decode it
        $photos = $this->photos;
        if (is_string($photos)) {
            $photos = json_decode($photos, true);
        }

        if (!is_array($photos) || empty($photos)) {
            return [];
        }

        return array_map(function ($photo) {
            // If photo is already a full URL, return it
            if (filter_var($photo, FILTER_VALIDATE_URL)) {
                return $photo;
            }

            // If photo starts with /storage/, use asset helper
            if (str_starts_with($photo, '/storage/')) {
                return asset($photo);
            }

            // If photo is a relative path without /storage/, add it
            if (!str_starts_with($photo, '/')) {
                return asset('/storage/' . $photo);
            }

            // Default: use asset helper
            return asset($photo);
        }, $photos);
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