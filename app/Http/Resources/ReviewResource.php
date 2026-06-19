<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => (int) $this->rating,
            'rating_display' => $this->getRatingDisplay(),
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toISOString(),
            'created_at_display' => $this->created_at?->format('M d, Y'),
            'user' => $this->whenLoaded('user', function () {
                return new UserResource($this->user);
            }),
            'order' => $this->whenLoaded('order', function () {
                return new OrderResource($this->order);
            }),
        ];
    }

    protected function getRatingDisplay(): string
    {
        return str_repeat('⭐', (int) $this->rating) . str_repeat('☆', 5 - (int) $this->rating);
    }
}