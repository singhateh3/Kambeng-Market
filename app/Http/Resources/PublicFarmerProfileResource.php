<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public, unauthenticated view of a farmer's profile — deliberately
 * separate from FarmerProfileResource, which is also used by the farmer
 * viewing their OWN profile and is appropriately richer there (email,
 * phone, revenue, internal verification timestamps). None of that belongs
 * on a route anyone can hit with no auth.
 */
class PublicFarmerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_name' => $this->farm_name,
            'farm_location' => $this->farm_location,
            'bio' => $this->bio,
            'is_verified' => $this->isVerified(),
            'verification_status' => $this->verification_status_label,
            'created_at' => $this->created_at?->toISOString(),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'location' => $this->user->location,
                    // See User::getAvatarUrlAttribute() — handles both a
                    // legacy local path and a Cloudinary secure_url; a bare
                    // asset($this->user->avatar) (the old code here) breaks
                    // both cases in different ways.
                    'avatar' => $this->user->avatar_url,
                ];
            }),
            'average_rating' => $this->when(isset($this->average_rating),
                round($this->average_rating, 1)
            ),
            'active_products_count' => $this->when(isset($this->active_products_count),
                $this->active_products_count
            ),
            'products_sold_count' => $this->when(isset($this->products_sold_count),
                $this->products_sold_count
            ),
        ];
    }

    public function with(Request $request): array
    {
        return [
            'success' => true,
        ];
    }
}
