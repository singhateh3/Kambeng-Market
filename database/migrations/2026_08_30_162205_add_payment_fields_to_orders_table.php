<?php

// database/migrations/xxxx_xx_xx_xxxxxx_add_payment_fields_to_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // COD is the only accepted method today — the DB-level enum
            // intentionally lists only what the app actually supports right
            // now (not Wave/QMoney/Africell), so it stays an accurate
            // constraint rather than a forward-looking guess. Widen it in
            // its own migration when a given gateway is actually built.
            if (!Schema::hasColumn('orders', 'payment_method')) {
                $table->enum('payment_method', ['cod'])->default('cod')->after('delivery_method');
            }

            // Separate from order status by design — an order can be
            // delivered without being paid (in theory) and paid without
            // being delivered (for a future gateway), so these must never
            // be collapsed into one field. failed/refunded aren't reachable
            // by anything today (no gateway, no refund flow), but are
            // included now so a future payment integration doesn't need its
            // own migration just to add them.
            if (!Schema::hasColumn('orders', 'payment_status')) {
                $table->enum('payment_status', ['pending', 'paid', 'failed', 'cancelled', 'refunded'])
                    ->default('pending')
                    ->after('payment_method');
            }
        });

        // Existing rows predate these columns; the column-level defaults
        // above already give every one of them payment_method=cod and
        // payment_status=pending on backfill — no separate UPDATE needed,
        // and no existing row is modified beyond gaining these two values.
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'payment_status')) {
                $table->dropColumn('payment_status');
            }
            if (Schema::hasColumn('orders', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
        });
    }
};
