<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'buyer_id',
        'product_id',
        'quantity',
        'total_price',
        'status',
        'special_instructions',
        'delivery_method',
        'delivery_deadline',
        'pickup_date',
        'delivery_address',
        'order_date',
        'payment_method',
        'payment_status',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'total_price' => 'decimal:2',
        'order_date' => 'datetime',
        'delivery_deadline' => 'date',
        'pickup_date' => 'date',
    ];

    /**
     * Get the buyer who placed the order.
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /**
     * Get the product that was ordered.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the farmer for this order (through product).
     */
    public function farmer()
    {
        return $this->hasOneThrough(
            User::class,
            Product::class,
            'id',           // Foreign key on products table
            'id',           // Foreign key on users table
            'product_id',   // Local key on orders table
            'farmer_id'     // Local key on products table
        );
    }

    /**
     * Get the review for this order.
     */
    public function review()
    {
        return $this->hasOne(Review::class);
    }

    /**
     * Get the dispute for this order, if one has been reported.
     */
    public function dispute()
    {
        return $this->hasOne(Dispute::class);
    }

    /**
     * The only status changes allowed from a given current status. Shared
     * by OrderController and AdminOrderController — previously only the
     * farmer-facing controller enforced this, so an admin call could jump
     * an order straight from 'pending' to 'delivered' in one request,
     * silently triggering the COD-auto-paid derivation below without the
     * order ever passing through 'confirmed'/'shipped'.
     */
    public const VALID_STATUS_TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::VALID_STATUS_TRANSITIONS[$this->status] ?? []);
    }

    /**
     * What payment_status should become as a side effect of transitioning
     * order status to $newOrderStatus, if anything — null means leave
     * payment_status exactly as it is (confirmed/shipped don't touch it).
     * Shared by OrderController and AdminOrderController so both apply the
     * same COD-at-delivery rule instead of duplicating it.
     */
    public function derivedPaymentStatus(string $newOrderStatus): ?string
    {
        if ($newOrderStatus === 'delivered' && $this->payment_method === 'cod') {
            return 'paid';
        }

        if ($newOrderStatus === 'cancelled') {
            return 'cancelled';
        }

        return null;
    }

    /**
     * Check if the order is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the order is confirmed.
     */
    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    /**
     * Check if the order is delivered.
     */
    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    /**
     * Get the status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'shipped' => 'Shipped',
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get the status color.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'yellow',
            'confirmed' => 'blue',
            'shipped' => 'purple',
            'delivered' => 'green',
            'cancelled' => 'red',
            default => 'gray',
        };
    }
}