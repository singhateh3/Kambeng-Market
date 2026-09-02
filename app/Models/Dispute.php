<?php

// app/Models/Dispute.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    use HasFactory;

    public const REASONS = [
        'item_not_received',
        'item_not_as_described',
        'quality_issue',
        'wrong_item',
        'farmer_unresponsive',
        'other',
    ];

    // Enforced by OrderController::report() — only orders in one of these
    // statuses can be reported (see that method for the reasoning).
    public const REPORTABLE_ORDER_STATUSES = ['confirmed', 'shipped', 'delivered'];

    public const ACTIVE_STATUSES = ['open', 'under_review'];

    // Set only alongside status resolved/rejected — resolving a dispute
    // must never implicitly mean a refund (see AdminDisputeController).
    public const REFUND_DECISIONS = ['no_refund', 'full_refund', 'partial_refund'];

    protected $fillable = [
        'order_id',
        'reported_by',
        'reason',
        'description',
        'status',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
        'refund_decision',
        'refund_amount',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'refund_amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
