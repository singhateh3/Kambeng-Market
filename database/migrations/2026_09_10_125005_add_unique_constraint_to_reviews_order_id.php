<?php

// database/migrations/2026_09_10_125005_add_unique_constraint_to_reviews_order_id.php
//
// P1 rate-limit audit finding: OrderController::review() only ever checked
// "does this order already have a review?" in application code
// (if ($order->review) { ... 422 }) — reviews.order_id itself had no
// unique constraint, so two near-simultaneous requests for the same order
// could both pass that check and both insert, producing two reviews for
// one order. A dedicated per-user rate limiter (review-create, see
// AppServiceProvider) narrows the window but doesn't close it; this closes
// it at the data layer, the same way disputes.order_id was already
// protected (see 2026_08_30_210741_create_disputes_table.php).
//
// Defensive: refuses to add the constraint if duplicate rows already
// exist, rather than silently deleting/choosing one to keep — that's a
// data decision for a human, not something a migration should do
// unattended.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'reviews_order_id_unique';

    public function up(): void
    {
        if ($this->indexExists()) {
            return;
        }

        $duplicates = DB::table('reviews')
            ->select('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('order_id');

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'Cannot add a unique constraint on reviews.order_id — duplicate '
                . 'reviews already exist for order_id(s): ' . $duplicates->implode(', ')
                . '. Resolve which review to keep for each of these orders manually, '
                . 'then re-run this migration.'
            );
        }

        Schema::table('reviews', function (Blueprint $table) {
            $table->unique('order_id', self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        if (!$this->indexExists()) {
            return;
        }

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(self::INDEX_NAME);
        });
    }

    /**
     * Same driver-aware existence check used elsewhere (see the
     * performance-indexes migration) — no doctrine/dbal in this app, so
     * Schema::hasIndex() isn't available.
     */
    private function indexExists(): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        return match ($driver) {
            'mysql' => DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', 'reviews')
                ->where('index_name', self::INDEX_NAME)
                ->exists(),
            'pgsql' => DB::table('pg_indexes')
                ->where('tablename', 'reviews')
                ->where('indexname', self::INDEX_NAME)
                ->exists(),
            'sqlite' => collect(DB::select('PRAGMA index_list("reviews")'))
                ->pluck('name')
                ->contains(self::INDEX_NAME),
            default => false,
        };
    }
};
