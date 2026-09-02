<?php

// app/Services/PaymentConfirmationService.php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Log;

/**
 * The single place an order is ever moved from 'awaiting_payment' to
 * 'pending'/paid. Previously this logic was duplicated between
 * ModemPayWebhookController and ReconcileModemPayTransactions — if both
 * ran for the same order at nearly the same moment (a webhook arriving
 * while the reconciliation job's HTTP call to ModemPay was still in
 * flight), the second one's unconditional update() would still write
 * again and the notification would fire a second time.
 *
 * Fixed the same way PayoutReleaseService closes its own double-release
 * race: one atomic conditional UPDATE, not a check-then-write sequence.
 * Only the caller whose UPDATE actually matches a row (status still
 * 'awaiting_payment' at the exact moment the statement runs) proceeds to
 * touch the ledger or fire the notification — the other gets
 * 'already_processed' and does nothing further.
 */
class PaymentConfirmationService
{
    /**
     * @return array{success: bool, reason: ?string}
     */
    public function confirmPayment(Order $order, ?float $verifiedAmount = null): array
    {
        if ($verifiedAmount !== null && abs($verifiedAmount - (float) $order->total_price) > 0.01) {
            Log::critical("Payment confirmation for order #{$order->id}: amount ({$verifiedAmount}) does not match order total ({$order->total_price}) — not marking paid, flagged for manual review.");
            return ['success' => false, 'reason' => 'amount_mismatch'];
        }

        $claimed = Order::where('id', $order->id)
            ->where('status', 'awaiting_payment')
            ->update([
                'status' => 'pending',
                'payment_status' => 'paid',
                'payment_confirmed_at' => now(),
            ]);

        if ($claimed === 0) {
            // Already processed by the other caller (webhook vs.
            // reconciliation racing each other) — correct, expected no-op,
            // not an error.
            return ['success' => false, 'reason' => 'already_processed'];
        }

        PaymentTransaction::where('order_id', $order->id)
            ->where('type', 'charge')
            ->where('status', 'pending')
            ->update(['status' => 'succeeded']);

        $order->refresh()->load(['buyer', 'product', 'product.farmer']);

        try {
            app(NotificationService::class)->orderPlaced($order->product->farmer, $order);
        } catch (\Exception $e) {
            Log::error('Error sending order placed notification: ' . $e->getMessage());
        }

        return ['success' => true, 'reason' => null];
    }
}
