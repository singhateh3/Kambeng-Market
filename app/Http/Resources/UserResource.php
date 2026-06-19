<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'location' => $this->location,
            'role' => $this->role,
            'avatar' => $this->avatar ? asset($this->avatar) : null,
            'verified_at' => $this->verified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'farmer_profile' => $this->whenLoaded('farmerProfile', function () {
                return new FarmerProfileResource($this->farmerProfile);
            }),
            'is_farmer' => $this->isFarmer(),
            'is_buyer' => $this->isBuyer(),
            'is_admin' => $this->isAdmin(),
        ];
    }
}