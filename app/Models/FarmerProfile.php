<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class FarmerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'farm_name',
        'farm_location',
        'bio',
        'id_verified',
    ];

    protected $casts = [
        'id_verified' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the farmer profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all products for this farmer.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'farmer_id', 'user_id');
    }

    /**
     * Get all orders for this farmer (through products).
     */
    public function orders(): HasManyThrough
    {
        return $this->hasManyThrough(
            Order::class,
            Product::class,
            'farmer_id',        // Foreign key on products table
            'product_id',       // Foreign key on orders table
            'user_id',          // Local key on farmer_profiles table
            'id'                // Local key on products table
        );
    }

    /**
     * Get all reviews for this farmer (through orders and products).
     */
    public function reviews(): HasManyThrough
    {
        return $this->hasManyThrough(
            Review::class,
            Order::class,
            'product_id',       // Foreign key on orders table
            'order_id',         // Foreign key on reviews table
            'user_id',          // Local key on farmer_profiles table
            'id'                // Local key on orders table
        )->whereHas('order.product', function ($query) {
            $query->where('farmer_id', $this->user_id);
        });
    }

    /**
     * Alternative: Get reviews through products using a custom query
     */
    public function getReviewsAttribute()
    {
        return Review::whereHas('order.product', function ($query) {
            $query->where('farmer_id', $this->user_id);
        })->get();
    }

    /**
     * Get the average rating for this farmer.
     */
    public function getAverageRatingAttribute(): ?float
    {
        return Review::whereHas('order.product', function ($query) {
            $query->where('farmer_id', $this->user_id);
        })->whereNotNull('rating')->avg('rating');
    }

    /**
     * Scope a query to only include verified farmers.
     */
    public function scopeVerified($query)
    {
        return $query->where('id_verified', true);
    }

    /**
     * Scope a query to only include unverified farmers.
     */
    public function scopeUnverified($query)
    {
        return $query->where('id_verified', false);
    }

    /**
     * Scope a query to search by farm name or location.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('farm_name', 'like', "%{$search}%")
              ->orWhere('farm_location', 'like', "%{$search}%")
              ->orWhere('bio', 'like', "%{$search}%");
        });
    }

    /**
     * Check if the farmer is verified.
     */
    public function isVerified(): bool
    {
        return (bool) $this->id_verified;
    }

    /**
     * Get the verification status label.
     */
    public function getVerificationStatusLabelAttribute(): string
    {
        return $this->isVerified() ? 'Verified' : 'Unverified';
    }

    /**
     * Get the verification status color.
     */
    public function getVerificationStatusColorAttribute(): string
    {
        return $this->isVerified() ? 'green' : 'red';
    }

    /**
     * Get the full farm address.
     */
    public function getFullAddressAttribute(): string
    {
        return $this->farm_location . ' - ' . ($this->user?->location ?? '');
    }

    /**
     * Get the display name for the farm.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->farm_name ?? $this->user?->name ?? 'Unknown Farm';
    }

    /**
     * Get the total number of active products.
     */
    public function getActiveProductsCountAttribute(): int
    {
        return $this->products()->active()->count();
    }

    /**
     * Get the total number of products sold.
     */
    public function getProductsSoldCountAttribute(): int
    {
        return $this->products()->where('status', 'sold')->count();
    }

    /**
     * Get the total revenue from delivered orders.
     */
    public function getTotalRevenueAttribute(): float
    {
        return (float) Order::whereHas('product', function ($query) {
            $query->where('farmer_id', $this->user_id);
        })->where('status', 'delivered')->sum('total_price');
    }

    /**
     * Get the completion percentage of the profile.
     */
    public function getCompletionPercentageAttribute(): int
    {
        $fields = [
            'farm_name',
            'farm_location',
            'bio',
        ];
        
        $filled = 0;
        foreach ($fields as $field) {
            if (!empty($this->$field)) {
                $filled++;
            }
        }
        
        return (int) round(($filled / count($fields)) * 100);
    }

    // app/Models/FarmerProfile.php

/**
 * Get all reviews for this farmer using a custom query.
 */
public function getFarmerReviews()
{
    return Review::whereHas('order.product', function ($query) {
        $query->where('farmer_id', $this->user_id);
    })->with(['order', 'user']);
}

/**
 * Get the average rating for this farmer.
 */
public function getFarmerAverageRating(): ?float
{
    return $this->getFarmerReviews()->whereNotNull('rating')->avg('rating');
}

/**
 * Get the total number of reviews for this farmer.
 */
public function getFarmerReviewsCount(): int
{
    return $this->getFarmerReviews()->count();
}
}