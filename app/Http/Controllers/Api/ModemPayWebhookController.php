<?php

// app/Http/Controllers/Api/ModemPayWebhookController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\WebhookEvent;
use App\Services\ModemPayClient;
use App\Services\PaymentConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public, unauthenticated route — signature verification is the auth. See
 * ModemPayClient::verifyWebhookSignature() for the exact confirmed
 * algorithm (HMAC-SHA512 over the raw body, no timestamp component).
 *
 * ModemPay's own docs give no idempotency guarantee at this layer (unlike
 * their Transfer API) and retry up to 3 times on a non-200 — WebhookEvent's
 * dedup is what actually prevents double-processing here, not the
 * signature check alone.
 *
 * Event names (customer.*, payment_intent.*, charge.*, transfer.*) are
 * exactly as listed in ModemPay's webhook documentation, verified this
 * session — not assumed.
 */
class ModemPayWebhookController extends Controller
{
    private const HANDLED_EVENTS = [
        'charge.succeeded',
        'charge.cancelled',
        'charge.expired',
        'payment_intent.cancelled',
        'payment_intent.expired',
        'transfer.succeeded',
        'transfer.failed',
        'transfer.flagged',
        'transfer.cancelled',
        'transfer.reversed',
    ];

    public function handle(Request $request, ModemPayClient $modemPay): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('x-modem-signature');

        if (!$modemPay->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('ModemPay webhook rejected: invalid signature.');
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $body = json_decode($rawBody, true);
        $eventType = $body['event'] ?? null;
        $payload = $body['payload'] ?? [];

        if (!$eventType) {
            Log::warning('ModemPay webhook rejected: missing event type.', ['body' => $body]);
            return response()->json(['message' => 'Missing event type'], 400);
        }

        // No single stable top-level event id was confirmed in ModemPay's
        // docs — derive the most specific identifier available so the
        // dedup key is at least as unique as what ModemPay actually sends.
        $eventId = $payload['id'] ?? $payload['transfer_reference'] ?? hash('sha256', $rawBody);

        // Always acknowledge with 200 even for an event type we don't
        // handle — per ModemPay's own guidance, and so an unrecognized
        // event doesn't get endlessly retried.
        if (!in_array($eventType, self::HANDLED_EVENTS, true)) {
            Log::info("ModemPay webhook: unhandled event type '{$eventType}', acknowledged without action.");
            return response()->json(['message' => 'ok']);
        }

        try {
            DB::transaction(function () use ($eventType, $eventId, $payload, $body) {
                // Dedup insert happens inside the same transaction as the
                // business logic below — if processing throws, the whole
                // thing (including this row) rolls back, so a genuine
                // retry can still reprocess it. If it commits, this row
                // and every effect below are atomic together.
                $existing = WebhookEvent::where('provider', 'modempay')->where('event_id', $eventId)->lockForUpdate()->first();
                if ($existing) {
                    return; // already processed — no-op, still returns 200 below
                }

                WebhookEvent::create([
                    'provider' => 'modempay',
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'payload' => $body,
                    'processed_at' => now(),
                ]);

                match (true) {
                    $eventType === 'charge.succeeded' => $this->handleChargeSucceeded($payload),
                    in_array($eventType, ['charge.cancelled', 'charge.expired', 'payment_intent.cancelled', 'payment_intent.expired'], true)
                        => $this->handlePaymentFailedOrExpired($payload, $eventType),
                    $eventType === 'transfer.succeeded' => $this->handleTransferSucceeded($payload),
                    in_array($eventType, ['transfer.failed', 'transfer.flagged', 'transfer.cancelled', 'transfer.reversed'], true)
                        => $this->handleTransferFailed($payload, $eventType),
                    default => null,
                };
            });
        } catch (\Throwable $e) {
            Log::error("ModemPay webhook processing failed for event '{$eventType}': " . $e->getMessage());
            // Non-200 so ModemPay retries — the transaction above rolled
            // back, so this is safe to reprocess.
            return response()->json(['message' => 'Processing error'], 500);
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * The order-correlation field was never confirmed — ModemPay's docs
     * never clarified whether a "charge" is the same object as the
     * Payment Intent or a distinct one with its own id. Checked
     * defensively against every plausible field rather than assumed.
     *
     * Task 11 confirmed live that payment_intent_id (UUID) and
     * intent_secret are two DIFFERENT values — orders.modempay_intent_id
     * now stores only the former, orders.modempay_intent_secret the
     * latter (see ModemPayClient::createPaymentIntent()). A webhook
     * payload's actual correlation field was never live-confirmed (out of
     * scope — no reachable webhook URL was available this session), so
     * this checks id/payment_intent_id against the id column and
     * intent_secret against the secret column, rather than assuming
     * whichever field the payload uses lines up with modempay_intent_id.
     */
    private function findOrderForPaymentEvent(array $payload): ?Order
    {
        $idCandidates = array_filter([
            $payload['id'] ?? null,
            $payload['payment_intent_id'] ?? null,
        ]);

        foreach ($idCandidates as $candidate) {
            $order = Order::where('modempay_intent_id', $candidate)->first();
            if ($order) {
                return $order;
            }
        }

        if (!empty($payload['intent_secret'])) {
            $order = Order::where('modempay_intent_secret', $payload['intent_secret'])->first();
            if ($order) {
                return $order;
            }
        }

        Log::critical('ModemPay webhook: could not correlate payment event to any order.', ['payload' => $payload]);
        return null;
    }

    private function handleChargeSucceeded(array $payload): void
    {
        $order = $this->findOrderForPaymentEvent($payload);
        if (!$order) {
            return; // unknown order — logged in findOrderForPaymentEvent()
        }

        // Server-controlled — the actual state transition, ledger update,
        // and notification all happen only if this claim succeeds; if
        // ReconcileModemPayTransactions already processed this order a
        // moment earlier, this is a correct, silent no-op (see
        // PaymentConfirmationService for why that's safe).
        $paidAmount = isset($payload['amount']) ? (float) $payload['amount'] : null;
        app(PaymentConfirmationService::class)->confirmPayment($order, $paidAmount);
    }

    private function handlePaymentFailedOrExpired(array $payload, string $eventType): void
    {
        $order = $this->findOrderForPaymentEvent($payload);
        if (!$order) {
            return;
        }

        $paymentStatus = str_contains($eventType, 'expired') ? 'expired' : 'failed';

        // Same atomic-claim shape as PaymentConfirmationService — this
        // webhook and the ExpireAwaitingPaymentOrders scheduled job both
        // touch the same 'awaiting_payment' orders; the conditional
        // update means whichever runs first wins, the other is a no-op
        // rather than a redundant (if value-idempotent) second write.
        $claimed = Order::where('id', $order->id)
            ->where('status', 'awaiting_payment')
            ->update([
                'status' => 'cancelled',
                'payment_status' => $paymentStatus,
            ]);

        if ($claimed === 0) {
            return;
        }

        PaymentTransaction::where('order_id', $order->id)
            ->where('type', 'charge')
            ->where('status', 'pending')
            ->update(['status' => 'failed']);
    }

    private function findOrderForTransferEvent(array $payload): ?Order
    {
        $transferId = $payload['id'] ?? null;
        if ($transferId) {
            $order = Order::where('modempay_transfer_id', $transferId)->first();
            if ($order) {
                return $order;
            }
        }

        Log::critical('ModemPay webhook: could not correlate transfer event to any order.', ['payload' => $payload]);
        return null;
    }

    private function handleTransferSucceeded(array $payload): void
    {
        $order = $this->findOrderForTransferEvent($payload);
        if (!$order || $order->payout_status !== 'released') {
            return;
        }

        $order->update(['payout_status' => 'paid']);

        PaymentTransaction::where('order_id', $order->id)
            ->where('type', 'payout')
            ->where('modempay_reference', $payload['id'] ?? null)
            ->update(['status' => 'succeeded']);
    }

    private function handleTransferFailed(array $payload, string $eventType): void
    {
        $order = $this->findOrderForTransferEvent($payload);
        if (!$order) {
            return;
        }

        Log::error("ModemPay transfer for order #{$order->id} ended in '{$eventType}' — needs admin attention.", $payload);

        $order->update(['payout_status' => 'failed']);

        PaymentTransaction::where('order_id', $order->id)
            ->where('type', 'payout')
            ->where('modempay_reference', $payload['id'] ?? null)
            ->update(['status' => 'failed']);
    }
}
