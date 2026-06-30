<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'location',
        'role',
        'avatar',
        'verified_at',
        'verification_requested_at',
        'verification_status',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'verified_at' => 'datetime',
        'verification_requested_at' => 'datetime',
        'password' => 'hashed',
    ];

    // Relationships
    public function farmerProfile()
    {
        return $this->hasOne(FarmerProfile::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'farmer_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    // Role checks
    public function isFarmer(): bool
    {
        return $this->role === 'farmer';
    }

    public function isBuyer(): bool
    {
        return $this->role === 'buyer';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    // Verification methods
    public function isVerified(): bool
    {
        return $this->verification_status === 'approved' && !is_null($this->verified_at);
    }

    public function isVerificationPending(): bool
    {
        return $this->verification_status === 'pending';
    }

    public function isVerificationRejected(): bool
    {
        return $this->verification_status === 'rejected';
    }

    public function requestVerification(): void
    {
        $this->update([
            'verification_status' => 'pending',
            'verification_requested_at' => now(),
        ]);
    }

    public function approveVerification(): void
    {
        $this->update([
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);

        if ($this->farmerProfile) {
            $this->farmerProfile->update([
                'id_verified' => true,
            ]);
        }
    }

    public function rejectVerification(?string $reason = null): void
    {
        $this->update([
            'verification_status' => 'rejected',
        ]);

        if ($this->farmerProfile) {
            $this->farmerProfile->update([
                'id_verified' => false,
                'rejection_reason' => $reason,
                'rejected_at' => now(),
            ]);
        }
    }

    // Helper method to update profile
    public function updateProfile(array $data): void
    {
        $userData = [];
        $farmerData = [];

        // Separate user and farmer data
        foreach ($data as $key => $value) {
            if (in_array($key, ['name', 'phone', 'location', 'avatar'])) {
                $userData[$key] = $value;
            } elseif (in_array($key, ['bio', 'farm_name', 'farm_location'])) {
                $farmerData[$key] = $value;
            }
        }

        // Update user
        if (!empty($userData)) {
            $this->update($userData);
        }

        // Update farmer profile if user is a farmer and has farmer profile
        if (!empty($farmerData) && $this->isFarmer() && $this->farmerProfile) {
            $this->farmerProfile->update($farmerData);
        }
    }

    // Accessors
    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->avatar) {
            return asset('storage/' . $this->avatar);
        }
        return null;
    }

    public function getVerificationStatusLabelAttribute(): string
    {
        return match ($this->verification_status) {
            'approved' => 'Approved',
            'pending' => 'Pending',
            'rejected' => 'Rejected',
            default => 'Not Submitted',
        };
    }

    public function getVerificationStatusColorAttribute(): string
    {
        return match ($this->verification_status) {
            'approved' => 'green',
            'pending' => 'yellow',
            'rejected' => 'red',
            default => 'gray',
        };
    }
}
