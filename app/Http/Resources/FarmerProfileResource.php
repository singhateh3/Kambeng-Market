<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmerProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_name' => $this->farm_name,
            'farm_location' => $this->farm_location,
            'full_address' => $this->full_address,
            'bio' => $this->bio,
            'is_verified' => $this->isVerified(),
            'verification_status' => $this->verification_status_label,
            'verification_status_color' => $this->verification_status_color,
            'created_at' => $this->created_at?->toISOString(),
            
            // Relationships
            'user' => $this->whenLoaded('user', function () {
                return new UserResource($this->user);
            }),
            
            // Aggregates
            'average_rating' => $this->when(isset($this->average_rating), 
                round($this->average_rating, 1)
            ),
            'active_products_count' => $this->when(isset($this->active_products_count), 
                $this->active_products_count
            ),
            'products_sold_count' => $this->when(isset($this->products_sold_count), 
                $this->products_sold_count
            ),
            'total_revenue' => $this->when(isset($this->total_revenue), 
                $this->total_revenue
            ),
            'total_revenue_formatted' => $this->when(isset($this->total_revenue), 
                'GMD ' . number_format($this->total_revenue, 2)
            ),
            'profile_completion' => $this->completion_percentage,
        ];
    }

    /**
     * Customize the response for the resource.
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
        ];
    }
}