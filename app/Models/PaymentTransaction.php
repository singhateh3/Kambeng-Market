<?php

// app/Models/PaymentTransaction.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The financial ledger. Append-only by convention — no code path should
 * ever update or delete a row here. A correction (a failed payout retried,
 * a partial refund on an already-charged order) is always a new row, never
 * a mutation of an existing one. See the creating migration for the full
 * rationale.
 */
class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'type',
        'amount',
        'currency',
        'commission_amount',
        'status',
        'modempay_reference',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Record that a refund is owed, without claiming it's actually been
     * paid back yet. ModemPay's docs document no public refund API — the
     * `status` field includes 'refunded' as a possible value, so refunds
     * clearly happen in their system somehow, but there's no confirmed way
     * to trigger one programmatically. This row stays 'pending' until an
     * admin confirms (via AdminOrderController::confirmRefund()) that the
     * refund was actually processed through ModemPay's own dashboard —
     * order.payment_status is deliberately left as 'paid' until then,
     * since claiming 'refunded' before money has actually moved would be
     * inaccurate.
     */
    public static function recordPendingRefund(Order $order, float $amount, string $type = 'refund', ?string $note = null): self
    {
        return self::create([
            'order_id' => $order->id,
            'type' => $type,
            'amount' => $amount,
            'currency' => 'GMD',
            'commission_amount' => round($amount * (float) $order->commission_rate, 2),
            'status' => 'pending',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'metadata' => array_filter(['note' => $note]),
        ]);
    }
}
