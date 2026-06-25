<?php

// app/Http/Resources/UserResource.php

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
            'verification_status' => $this->verification_status ?? 'pending',
            'verification_status_label' => $this->verification_status_label ?? 'Pending',
            'verification_requested_at' => $this->verification_requested_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'farmer_profile' => $this->whenLoaded('farmerProfile', function () {
                return [
                    'id' => $this->farmerProfile->id,
                    'farm_name' => $this->farmerProfile->farm_name,
                    'farm_location' => $this->farmerProfile->farm_location,
                    'bio' => $this->farmerProfile->bio,
                    'id_verified' => (bool) $this->farmerProfile->id_verified,
                    'verification_notes' => $this->farmerProfile->verification_notes,
                    'rejection_reason' => $this->farmerProfile->rejection_reason,
                    'rejected_at' => $this->farmerProfile->rejected_at?->toISOString(),
                ];
            }),
            'is_farmer' => $this->isFarmer(),
            'is_buyer' => $this->isBuyer(),
            'is_admin' => $this->isAdmin(),
            'display_name' => $this->display_name,
        ];
    }
}