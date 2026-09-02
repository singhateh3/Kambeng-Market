<?php

// app/Console/Commands/ExpireAwaitingPaymentOrders.php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Console\Command;

/**
 * Safely expires an abandoned checkout — a buyer who started a ModemPay
 * payment intent but never completed it. Runs as a backstop alongside
 * (not instead of) the payment_intent.expired/.cancelled webhook — if a
 * webhook is ever lost, this still cleans the order up on a timeout
 * rather than leaving it stuck in 'awaiting_payment' forever.
 */
class ExpireAwaitingPaymentOrders extends Command
{
    protected $signature = 'orders:expire-awaiting-payment';
    protected $description = 'Cancel awaiting_payment orders past the configurable timeout with no successful payment';

    public function handle(): int
    {
        $timeoutMinutes = (int) config('commission.awaiting_payment_timeout_minutes');

        $orders = Order::where('status', 'awaiting_payment')
            ->where('created_at', '<=', now()->subMinutes($timeoutMinutes))
            ->get();

        $expired = 0;
        foreach ($orders as $order) {
            // Atomic conditional claim — this job and a payment_intent.
            // expired/.cancelled webhook can both touch the same order;
            // whichever runs first wins, the other is a clean no-op
            // rather than a redundant second write (see
            // ModemPayWebhookController::handlePaymentFailedOrExpired()).
            $claimed = Order::where('id', $order->id)
                ->where('status', 'awaiting_payment')
                ->update([
                    'status' => 'cancelled',
                    'payment_status' => 'expired',
                ]);

            if ($claimed === 0) {
                continue;
            }

            PaymentTransaction::where('order_id', $order->id)
                ->where('type', 'charge')
                ->where('status', 'pending')
                ->update(['status' => 'failed']);

            $expired++;
            $this->info("Expired awaiting_payment order #{$order->id}");
        }

        $this->info("Checked {$orders->count()} order(s), expired {$expired}.");

        return self::SUCCESS;
    }
}
