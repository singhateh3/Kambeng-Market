<?php

// app/Models/WebhookEvent.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Dedup record for inbound provider webhooks. See the creating migration —
 * ModemPay gives no idempotency guarantee at the webhook layer and retries
 * up to 3 times on a non-200 response, so this table is what actually
 * prevents double-processing a retried or duplicated delivery.
 */
class WebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
