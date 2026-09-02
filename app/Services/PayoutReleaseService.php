<?php

// app/Services/PayoutReleaseService.php

namespace App\Services;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Releases and pays out a farmer's share for one order. Shared by three
 * call sites — the buyer's manual confirm endpoint, the daily auto-release
 * job, and the dispute-rejected-past-window immediate-release path — so
 * the release logic lives in exactly one place.
 */
class PayoutReleaseService
{
    public const REASONS = ['buyer_confirmed', 'auto_released', 'admin_override', 'dispute_rejected_post_window'];

    public function __construct(private ModemPayClient $modemPay)
    {
    }

    /**
     * @return array{success: bool, reason: ?string}
     */
    public function release(Order $order, string $reason): array
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException("Invalid payout release reason: {$reason}");
        }

        if (!$order->isPayoutEligibleForRelease()) {
            return ['success' => false, 'reason' => 'not_eligible'];
        }

        $order->loadMissing('product.farmer.farmerProfile');
        $farmerProfile = $order->product->farmer->farmerProfile ?? null;

        if (!$farmerProfile || !$farmerProfile->hasSettlementDetails()) {
            Log::warning("Payout release blocked for order {$order->id}: farmer has no settlement details on file.");
            return ['success' => false, 'reason' => 'missing_settlement_info'];
        }

        $amountToPay = $this->computeOutstandingFarmerAmount($order);

        if ($amountToPay <= 0) {
            $order->update(['payout_status' => 'voided']);
            return ['success' => false, 'reason' => 'nothing_owed'];
        }

        // Atomic claim — a single UPDATE statement, not a check-then-write
        // sequence, so there is no window between "is this eligible" and
        // "claim it" for a race to land in. Two things it guards against
        // simultaneously:
        //   1. Two concurrent release() calls for the same order (e.g. the
        //      buyer clicking confirm right as the auto-release job runs)
        //      — only one UPDATE can ever match payout_status='pending_release'.
        //   2. A dispute created in the gap between the
        //      isPayoutEligibleForRelease() check above and this UPDATE —
        //      the whereNotExists is evaluated by the database as part of
        //      this same atomic statement, not against a stale in-memory
        //      read, so a dispute committed a moment after the check above
        //      but before this UPDATE executes still correctly blocks the
        //      claim. The isPayoutEligibleForRelease() check above exists
        //      only to give a clearer, cheaper "not eligible" response in
        //      the common case — this UPDATE is what actually enforces it.
        $claimed = Order::where('id', $order->id)
            ->where('payout_status', 'pending_release')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('disputes')
                    ->whereColumn('disputes.order_id', 'orders.id')
                    ->whereIn('disputes.status', Dispute::ACTIVE_STATUSES);
            })
            ->update([
                'payout_status' => 'released',
                'payout_release_reason' => $reason,
                'payout_released_at' => now(),
            ]);

        if ($claimed === 0) {
            return ['success' => false, 'reason' => 'already_claimed'];
        }

        // A fresh key per genuine release attempt — a retry after a
        // confirmed failure is a new attempt (new ledger row, new key,
        // see the class docblock on retries); this key only protects
        // against Kambeng's own HTTP layer resending *this* specific
        // request before knowing whether it reached ModemPay.
        $idempotencyKey = (string) Str::uuid();

        $ledgerRow = PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => 'payout',
            'amount' => $amountToPay,
            'currency' => 'GMD',
            'status' => 'pending',
            'idempotency_key' => $idempotencyKey,
            // Immutable snapshot of exactly what was used for this
            // transfer — a later change to the farmer's profile must
            // never alter this historical record.
            'metadata' => [
                'settlement_network' => $farmerProfile->settlement_network,
                'settlement_account_number' => $farmerProfile->settlement_account_number,
                'settlement_beneficiary_name' => $farmerProfile->settlement_beneficiary_name,
            ],
        ]);

        try {
            $response = $this->modemPay->createTransfer([
                'amount' => (string) $amountToPay,
                'currency' => 'GMD',
                'network' => $farmerProfile->settlement_network,
                'account_number' => $farmerProfile->settlement_account_number,
                'beneficiary_name' => $farmerProfile->settlement_beneficiary_name,
                'narration' => "Kambeng Market order #{$order->id}",
                'metadata' => ['order_id' => $order->id],
            ], $idempotencyKey);

            // A response was received — ModemPay accepted the request for
            // processing. The transfer's own success/failure is confirmed
            // asynchronously via the transfer.succeeded/.failed webhook
            // (see ModemPayWebhookController), not assumed here — their
            // docs show a transfer object's `status` field as "completed"
            // in one example while the webhook event name is
            // "transfer.succeeded"; these vocabularies were never
            // confirmed to match, so this ledger row stays 'pending'
            // until the webhook says otherwise, rather than trusting any
            // status string in this synchronous response.
            $ledgerRow->update([
                'modempay_reference' => $response['id'] ?? null,
                'metadata' => array_merge($ledgerRow->metadata, ['modempay_response' => $response]),
            ]);

            $order->update(['modempay_transfer_id' => $response['id'] ?? null]);

            return ['success' => true, 'reason' => null];
        } catch (RequestException $e) {
            // A definite HTTP error response — ModemPay rejected the
            // request outright (validation, auth, etc). Safe to know this
            // did not result in money moving.
            Log::error("ModemPay transfer request rejected for order {$order->id}: " . $e->getMessage());
            $ledgerRow->update(['status' => 'failed', 'metadata' => array_merge($ledgerRow->metadata, ['error' => $e->getMessage()])]);
            $order->update(['payout_status' => 'failed']);

            return ['success' => false, 'reason' => 'transfer_rejected'];
        } catch (ConnectionException|\Throwable $e) {
            // No response at all (timeout, DNS, connection reset, or any
            // other unclassified failure) — genuinely ambiguous whether
            // ModemPay actually processed this before the connection
            // dropped. No ModemPay endpoint to look a transfer up by our
            // own idempotency key was confirmed in their docs, so this
            // cannot be safely auto-resolved. Marked 'failed' for admin
            // visibility, but an admin retrying this MUST first confirm
            // via ModemPay's own dashboard that the original attempt did
            // not succeed — retrying blindly here risks a real duplicate
            // payment.
            Log::critical("ModemPay transfer for order {$order->id} failed with an AMBIGUOUS outcome (no response received) — verify with ModemPay directly before retrying. " . $e->getMessage());
            $ledgerRow->update(['status' => 'failed', 'metadata' => array_merge($ledgerRow->metadata, ['error' => $e->getMessage(), 'ambiguous_outcome' => true])]);
            $order->update(['payout_status' => 'failed']);

            return ['success' => false, 'reason' => 'transfer_ambiguous'];
        }
    }

    /**
     * The farmer's outstanding entitlement, net of any prior refunds
     * recorded in the ledger — never the raw snapshot alone once a partial
     * refund has happened.
     */
    private function computeOutstandingFarmerAmount(Order $order): float
    {
        $refundedFarmerShare = $order->paymentTransactions()
            ->whereIn('type', ['refund', 'partial_refund'])
            ->get()
            ->sum(fn ($tx) => round((float) $tx->amount * (1 - (float) $order->commission_rate), 2));

        return max(0, round((float) $order->farmer_net_amount - $refundedFarmerShare, 2));
    }
}
