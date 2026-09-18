<?php

// database/migrations/2026_09_17_000001_restrict_business_history_cascades_on_user_deletion.php
//
// Phase 3B — eliminates the user -> product -> order -> payment_transaction
// hard-delete cascade path identified in the Phase 3B audit.
// payment_transactions is documented, in its own migration
// (2026_08_31_110046_create_payment_transactions_table.php), as "The
// financial ledger... Append-only by convention... This is the source of
// truth" — yet every FK below cascaded, so deleting a user could silently
// destroy it along with orders, reviews, and disputes.
//
// Under the new deactivation/anonymization flow (see
// UserDeactivationService), the application never calls $user->delete()
// or $order->delete() for a user/order with any business history again —
// these FK changes are a database-level safety net against that ever
// happening by accident (a bug, a future code path, direct DB access),
// not something the normal flow is expected to exercise. RESTRICT (not
// SET NULL) is used specifically so an accidental attempt fails loudly
// with a constraint violation instead of silently leaving an order with a
// null buyer, a review with no author, etc. — a half-erased historical
// record would be worse than either fully preserving it or refusing the
// delete outright.
//
// Deliberately NOT changed (left exactly as-is):
//   - disputes.reviewed_by (SET NULL) — already the correct semantic; the
//     specific admin who happened to review an old dispute is a
//     low-stakes, optional attribution, not core business/financial data.
//   - notifications.user_id, saved_farmers.buyer_id/farmer_id (CASCADE) —
//     purely personal-inbox/bookmark data, never the risk this audit
//     identified.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGES = [
        ['table' => 'farmer_profiles', 'column' => 'user_id', 'on' => 'users'],
        ['table' => 'orders', 'column' => 'buyer_id', 'on' => 'users'],
        ['table' => 'orders', 'column' => 'product_id', 'on' => 'products'],
        ['table' => 'payment_transactions', 'column' => 'order_id', 'on' => 'orders'],
        ['table' => 'reviews', 'column' => 'order_id', 'on' => 'orders'],
        ['table' => 'reviews', 'column' => 'user_id', 'on' => 'users'],
        ['table' => 'disputes', 'column' => 'order_id', 'on' => 'orders'],
        ['table' => 'disputes', 'column' => 'reported_by', 'on' => 'users'],
    ];

    public function up(): void
    {
        foreach (self::CHANGES as $change) {
            Schema::table($change['table'], function (Blueprint $table) use ($change) {
                $table->dropForeign([$change['column']]);
                $table->foreign($change['column'])
                    ->references('id')->on($change['on'])
                    ->restrictOnDelete();
            });
        }

        $this->restorePartialDisputeIndex();
    }

    public function down(): void
    {
        foreach (self::CHANGES as $change) {
            Schema::table($change['table'], function (Blueprint $table) use ($change) {
                $table->dropForeign([$change['column']]);
                $table->foreign($change['column'])
                    ->references('id')->on($change['on'])
                    ->cascadeOnDelete();
            });
        }

        $this->restorePartialDisputeIndex();
    }

    /**
     * Altering disputes' FKs above (order_id, reported_by) forces a
     * full-table rebuild on SQLite — it has no in-place ALTER-constraint
     * support, so Laravel recreates the table and copies the data across.
     * That rebuild does not preserve the WHERE clause on the pre-existing
     * disputes_one_active_per_order partial unique index (see
     * 2026_08_30_210741_create_disputes_table.php): confirmed directly —
     * it survives the rebuild as a plain, non-partial unique index on
     * order_id alone, silently turning "at most one ACTIVE dispute per
     * order" into "at most one dispute per order, ever", which incorrectly
     * rejects a legitimate new dispute filed after a prior one was
     * resolved/rejected. Recreated here exactly as the original migration
     * defined it, same driver-conditional (MySQL has no partial-index
     * support, so it never had this index either — nothing to restore
     * there).
     */
    private function restorePartialDisputeIndex(): void
    {
        if (!in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'])) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS disputes_one_active_per_order');
        DB::statement(
            "CREATE UNIQUE INDEX disputes_one_active_per_order ON disputes (order_id) WHERE status IN ('open', 'under_review')"
        );
    }
};
