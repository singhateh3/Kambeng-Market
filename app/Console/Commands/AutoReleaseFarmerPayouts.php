<?php

// app/Console/Commands/AutoReleaseFarmerPayouts.php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\PayoutReleaseService;
use Illuminate\Console\Command;

/**
 * The 3-day buyer-protection auto-release: if the buyer hasn't confirmed
 * and no dispute is active, the farmer's payout releases automatically so
 * a buyer can't indefinitely withhold it just by not clicking confirm.
 */
class AutoReleaseFarmerPayouts extends Command
{
    protected $signature = 'orders:auto-release-payouts';
    protected $description = 'Release farmer payouts for delivered orders past the buyer-protection window with no active dispute';

    public function handle(PayoutReleaseService $payoutRelease): int
    {
        $days = (int) config('commission.auto_release_days');

        $orders = Order::where('status', 'delivered')
            ->where('payout_status', 'pending_release')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '<=', now()->subDays($days))
            ->get();

        $released = 0;
        foreach ($orders as $order) {
            // isPayoutEligibleForRelease() (checked again inside release())
            // re-verifies no active dispute at the moment of release, not
            // just at query time — a dispute filed between the query and
            // this loop iteration still correctly blocks it.
            $result = $payoutRelease->release($order, 'auto_released');
            if ($result['success']) {
                $released++;
                $this->info("Auto-released payout for order #{$order->id}");
            } else {
                $this->line("Skipped order #{$order->id}: {$result['reason']}");
            }
        }

        $this->info("Checked {$orders->count()} order(s), released {$released}.");

        return self::SUCCESS;
    }
}
