<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => (float) $this->quantity,
            'total_price' => (float) $this->total_price,
            'total_price_formatted' => 'GMD ' . number_format($this->total_price, 2),
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),
            'special_instructions' => $this->special_instructions,
            'delivery_method' => $this->delivery_method,
            'delivery_method_label' => $this->getDeliveryMethodLabel(),
            'delivery_deadline' => $this->delivery_deadline?->toISOString(),
            'delivery_deadline_display' => $this->delivery_deadline?->format('M d, Y'),
            'pickup_date' => $this->pickup_date?->toISOString(),
            'pickup_date_display' => $this->pickup_date?->format('M d, Y'),
            'order_date' => $this->order_date?->toISOString(),
            'order_date_display' => $this->order_date?->format('M d, Y H:i'),
            'created_at' => $this->created_at?->toISOString(),
            'buyer' => $this->whenLoaded('buyer', function () {
                return new UserResource($this->buyer);
            }),
            'product' => $this->whenLoaded('product', function () {
                return new ProductResource($this->product);
            }),
            'review' => $this->whenLoaded('review', function () {
                return new ReviewResource($this->review);
            }),
        ];
    }

    protected function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'shipped' => 'Shipped',
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
            default => ucfirst($this->status),
        };
    }

    protected function getStatusColor(): string
    {
        return match ($this->status) {
            'pending' => 'yellow',
            'confirmed' => 'blue',
            'shipped' => 'purple',
            'delivered' => 'green',
            'cancelled' => 'red',
            default => 'gray',
        };
    }

    protected function getDeliveryMethodLabel(): string
    {
        return match ($this->delivery_method) {
            'pickup' => 'Pickup',
            'farmer_delivery' => 'Farmer Delivery',
            default => ucfirst($this->delivery_method),
        };
    }
}