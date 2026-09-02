<?php

// database/migrations/2026_08_31_234943_add_modempay_intent_secret_to_orders_table.php
//
// Confirmed live against ModemPay's actual API this session (Task 11):
// a Payment Intent has TWO distinct identifiers, not one.
//   - payment_intent_id: a stable UUID, present under that name on the
//     create response, as `id` on the verify/transactions-list responses.
//     This is what `orders.modempay_intent_id` now stores — the general-
//     purpose correlation identifier (webhooks, reconciliation, ledger).
//   - intent_secret: a separate, much longer opaque token. Confirmed live
//     that GET /v1/payments/verify requires THIS specific value as its
//     `intent_secret` query param — passing payment_intent_id/id there
//     fails with a 500. Needs its own column.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('modempay_intent_secret')->nullable()->after('modempay_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('modempay_intent_secret');
        });
    }
};
