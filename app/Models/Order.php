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
        'payout_status',
        'payout_release_reason',
        'commission_rate',
        'commission_amount',
        'farmer_net_amount',
        'modempay_intent_id',
        'modempay_intent_secret',
        'modempay_transfer_id',
        'payment_confirmed_at',
        'delivered_at',
        'buyer_confirmed_at',
        'payout_released_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'total_price' => 'decimal:2',
        'commission_rate' => 'decimal:4',
        'commission_amount' => 'decimal:2',
        'farmer_net_amount' => 'decimal:2',
        'order_date' => 'datetime',
        'delivery_deadline' => 'date',
        'pickup_date' => 'date',
        'payment_confirmed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'buyer_confirmed_at' => 'datetime',
        'payout_released_at' => 'datetime',
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
     * The full financial ledger for this order. Append-only — see
     * PaymentTransaction.
     */
    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * The only status changes allowed from a given current status. Shared
     * by OrderController and AdminOrderController — previously only the
     * farmer-facing controller enforced this, so an admin call could jump
     * an order straight from 'pending' to 'delivered' in one request,
     * silently triggering the COD-auto-paid derivation below without the
     * order ever passing through 'confirmed'/'shipped'.
     *
     * 'awaiting_payment' is a new leading state: an order isn't real or
     * farmer-visible until a verified successful ModemPay webhook moves it
     * to 'pending' (see OrderController::store()/ModemPayWebhookController).
     * A checkout that fails or expires moves straight to 'cancelled' —
     * deliberately NOT a distinct order-level status; payment_status
     * records why (failed/expired), keeping order status and payment
     * status strictly separate state machines.
     */
    public const VALID_STATUS_TRANSITIONS = [
        'awaiting_payment' => ['pending', 'cancelled'],
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
     *
     * Deliberately returns null (does nothing) for a cancelled ModemPay
     * order whose payment already succeeded — real money was collected, so
     * that case needs an actual refund, not a relabel to 'cancelled'. The
     * caller (OrderController::cancel(), or the dispute refund flow) must
     * handle that explicitly rather than have it silently derived here.
     */
    public function derivedPaymentStatus(string $newOrderStatus): ?string
    {
        if ($newOrderStatus === 'delivered' && $this->payment_method === 'cod') {
            return 'paid';
        }

        if ($newOrderStatus === 'cancelled') {
            if ($this->payment_method === 'cod' || $this->payment_status !== 'paid') {
                return 'cancelled';
            }
            return null;
        }

        return null;
    }

    /**
     * Snapshot commission fields from the current config rate. Called only
     * at order creation — never again, so historical orders are unaffected
     * by a later rate change.
     */
    public function applyCommissionSnapshot(): void
    {
        $rate = (float) config('commission.rate');
        $this->commission_rate = $rate;
        $this->commission_amount = round($this->total_price * $rate, 2);
        $this->farmer_net_amount = round($this->total_price - $this->commission_amount, 2);
    }

    /**
     * Whether this order currently has a dispute that isn't yet resolved —
     * blocks both buyer confirmation and payout release (auto or manual),
     * regardless of which party filed it.
     */
    public function hasActiveDispute(): bool
    {
        return $this->dispute()->whereIn('status', Dispute::ACTIVE_STATUSES)->exists();
    }

    /**
     * Whether the farmer's payout can be released right now — delivered,
     * still awaiting release, no active dispute. Shared by the buyer
     * confirm endpoint, the auto-release scheduled job, and the
     * dispute-rejected-past-window immediate-release path.
     */
    public function isPayoutEligibleForRelease(): bool
    {
        return $this->status === 'delivered'
            && $this->payout_status === 'pending_release'
            && !$this->hasActiveDispute();
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
            'awaiting_payment' => 'Awaiting Payment',
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
            'awaiting_payment' => 'gray',
            'pending' => 'yellow',
            'confirmed' => 'blue',
            'shipped' => 'purple',
            'delivered' => 'green',
            'cancelled' => 'red',
            default => 'gray',
        };
    }
}