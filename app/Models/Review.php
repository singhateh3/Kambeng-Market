<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'user_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    /**
     * Get the order that was reviewed.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the user who wrote the review.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the farmer through the order.
     */
    public function farmer()
    {
        return $this->hasOneThrough(
            User::class,
            Order::class,
            'id',           // Foreign key on orders table
            'id',           // Foreign key on users table
            'order_id',     // Local key on reviews table
            'product_id'    // Local key on orders table
        )->whereHas('products', function ($query) {
            $query->where('farmer_id', 'users.id');
        });
    }
}