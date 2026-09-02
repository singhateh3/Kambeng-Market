<?php

// app/Console/Commands/ReconcileModemPayTransactions.php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\ModemPayClient;
use App\Services\PaymentConfirmationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety net against a lost/delayed charge.succeeded webhook. Re-checks
 * each still-awaiting_payment order directly via ModemPay's confirmed
 * GET /v1/payments/verify endpoint (the exact fields/status vocab were
 * verified this session — see ModemPayClient::verifyPaymentIntent()) —
 * chosen over ModemPay's transaction-list endpoint, whose exact filter/
 * response shape for this use case was never fully confirmed.
 *
 * Only checks orders old enough that a webhook has had a fair chance to
 * arrive, so this doesn't race a payment that's still genuinely in
 * flight.
 *
 * Uses the same PaymentConfirmationService as the webhook handler — the
 * two used to duplicate this logic independently, which meant a webhook
 * arriving while this job's HTTP call to ModemPay was still in flight
 * could result in a duplicate orderPlaced() notification. The service's
 * atomic conditional update means only whichever of the two actually
 * wins the awaiting_payment -> pending claim does anything further.
 */
class ReconcileModemPayTransactions extends Command
{
    protected $signature = 'orders:reconcile-modempay';
    protected $description = 'Cross-check awaiting_payment orders directly against ModemPay in case a webhook was lost';

    public function handle(ModemPayClient $modemPay, PaymentConfirmationService $paymentConfirmation): int
    {
        $orders = Order::where('status', 'awaiting_payment')
            ->whereNotNull('modempay_intent_secret')
            ->where('created_at', '<=', now()->subMinutes(5))
            ->get();

        $recovered = 0;

        foreach ($orders as $order) {
            try {
                $response = $modemPay->verifyPaymentIntent($order->modempay_intent_secret);
                $status = $response['data']['status'] ?? null;
            } catch (\Throwable $e) {
                Log::warning("Reconciliation: could not verify order #{$order->id}'s payment intent: " . $e->getMessage());
                continue;
            }

            if ($status !== 'successful') {
                continue; // still genuinely pending, or failed — the expire job / webhook handles those
            }

            $result = $paymentConfirmation->confirmPayment($order);

            if ($result['success']) {
                $recovered++;
                $this->warn("Reconciliation recovered order #{$order->id} — its charge.succeeded webhook appears to have been lost.");
            } elseif ($result['reason'] === 'already_processed') {
                // The webhook won the race while this loop was running —
                // correct, expected, not logged as a problem.
                $this->line("Order #{$order->id} was already confirmed (webhook arrived first).");
            }
        }

        $this->info("Checked {$orders->count()} order(s), recovered {$recovered}.");

        return self::SUCCESS;
    }
}
