<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavedFarmerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farmer_id' => $this->farmer_id,
            'created_at' => $this->created_at?->toISOString(),
            // UserResource (with farmerProfile eager-loaded), not the
            // heavier FarmerProfileResource — that one's average_rating/
            // product-count fields are live accessor-driven queries, which
            // would mean N+1 queries across a paginated list. The full
            // profile page uses FarmerProfileResource directly instead.
            'farmer' => $this->whenLoaded('farmer', function () {
                return new UserResource($this->farmer);
            }),
        ];
    }
}
