<?php

// database/migrations/2026_08_31_110045_add_payout_and_commission_fields_to_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Independent from `status` (fulfillment) and `payment_status`
            // (collection) by design — this is the third, separate state
            // machine tracking whether/when the farmer has actually been
            // paid their share. 'not_applicable' covers grandfathered COD
            // orders and any order whose payment never succeeded.
            $table->enum('payout_status', ['not_applicable', 'pending_release', 'released', 'paid', 'failed', 'voided'])
                ->default('not_applicable')
                ->after('payment_status');
            $table->enum('payout_release_reason', ['buyer_confirmed', 'auto_released', 'admin_override', 'dispute_rejected_post_window'])
                ->nullable()
                ->after('payout_status');

            // Snapshotted at order creation from config('commission.rate')
            // and never recalculated — a future rate change must not alter
            // historical orders.
            $table->decimal('commission_rate', 5, 4)->nullable()->after('total_price');
            $table->decimal('commission_amount', 10, 2)->nullable()->after('commission_rate');
            $table->decimal('farmer_net_amount', 10, 2)->nullable()->after('commission_amount');

            $table->string('modempay_intent_id')->nullable()->after('payment_method');
            $table->string('modempay_transfer_id')->nullable()->after('modempay_intent_id');

            // Server-controlled only — set exclusively from verified webhook
            // handling, never from any request input. See OrderController.
            $table->timestamp('payment_confirmed_at')->nullable();
            // Dedicated timestamp for the delivery transition specifically —
            // `updated_at` changes on any field update, not just this one,
            // and the 3-day buyer-protection window needs a precise anchor.
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('buyer_confirmed_at')->nullable();
            $table->timestamp('payout_released_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payout_status',
                'payout_release_reason',
                'commission_rate',
                'commission_amount',
                'farmer_net_amount',
                'modempay_intent_id',
                'modempay_transfer_id',
                'payment_confirmed_at',
                'delivered_at',
                'buyer_confirmed_at',
                'payout_released_at',
            ]);
        });
    }
};
