<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'farmer_id',
        'name',
        'category',
        'quantity',
        'unit',
        'price',
        'harvest_date',
        'expiry_date',
        'photos',
        'description',
        'status',
        'views_count',
    ];

    protected $casts = [
        'photos' => 'array',
        'harvest_date' => 'date',
        'expiry_date' => 'date',
        'price' => 'decimal:2',
        'quantity' => 'decimal:2',
        'views_count' => 'integer',
    ];

    /**
     * Get the farmer that owns the product.
     */
    public function farmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'farmer_id');
    }

    /**
     * Get the farmer profile for this product.
     */
    public function farmerProfile()
    {
        return $this->hasOneThrough(
            FarmerProfile::class,
            User::class,
            'id',
            'user_id',
            'farmer_id',
            'id'
        );
    }

    /**
     * Get the orders for the product.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the reviews for the product through orders.
     */
    public function reviews()
    {
        return $this->hasManyThrough(
            Review::class,
            Order::class,
            'product_id',
            'order_id',
            'id',
            'id'
        );
    }

    /**
     * Scope a query to only include active products.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                     ->where('expiry_date', '>=', now()->startOfDay());
    }

    /**
     * Check if the product is available.
     */
    public function isAvailable(): bool
    {
        return $this->status === 'active' && $this->expiry_date >= now()->startOfDay();
    }

    /**
     * Get the formatted price.
     */
    public function getFormattedPriceAttribute(): string
    {
        return 'GMD ' . number_format($this->price, 2);
    }

    /**
     * Get the status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'active' => 'Available',
            'sold' => 'Sold Out',
            default => ucfirst($this->status),
        };
    }
}