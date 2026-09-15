<?php

// database/migrations/2026_09_15_000000_add_missing_foreign_key_indexes.php
//
// Phase 1 P0 audit finding: products.farmer_id, orders.buyer_id,
// orders.product_id, and notifications.user_id are all
// foreignId()->constrained() columns with no dedicated index (see each
// column's create_*_table migration). Unlike MySQL/InnoDB, PostgreSQL
// (the production database) does NOT automatically index a referencing
// foreign-key column — only the explicit index() calls in
// 2026_09_10_002651_add_performance_indexes_to_products_and_orders_tables.php
// (products.status/created_at, orders.status/order_date) exist so far.
// These four are hit by real, frequent queries:
//   - products.farmer_id   — ProductController::myProducts()
//   - orders.buyer_id      — OrderController::index() (buyer's own orders),
//                            AdminOrderController::index()'s buyer_id filter
//   - orders.product_id    — whereHas('product', fn q => where('farmer_id', ...))
//                            in OrderController::index(), AdminOrderController::index(),
//                            FarmerProfileController::statistics()
//   - notifications.user_id — NotificationController::index()/unreadCount(),
//                             the latter polled every 30s per active user
//                             (see NotificationContext.jsx)
//
// notifications gets a composite (user_id, is_read) rather than a plain
// user_id index: NotificationController::unreadCount() filters on both
// columns together, and index() optionally filters is_read too — a
// composite index still serves a user_id-only query via its leftmost
// column, so this is not an extra index on top of a plain one, it's the
// same single index shaped to also cover the two-column filter.
//
// Purely additive — no existing migration is modified, no data or
// foreign-key constraint is touched.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        ['table' => 'products', 'columns' => ['farmer_id'], 'name' => 'products_farmer_id_index'],
        ['table' => 'orders', 'columns' => ['buyer_id'], 'name' => 'orders_buyer_id_index'],
        ['table' => 'orders', 'columns' => ['product_id'], 'name' => 'orders_product_id_index'],
        ['table' => 'notifications', 'columns' => ['user_id', 'is_read'], 'name' => 'notifications_user_id_is_read_index'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index) {
            if ($this->indexExists($index['table'], $index['name'])) {
                continue;
            }

            Schema::table($index['table'], function (Blueprint $table) use ($index) {
                $table->index($index['columns'], $index['name']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $index) {
            if (!$this->indexExists($index['table'], $index['name'])) {
                continue;
            }

            Schema::table($index['table'], function (Blueprint $table) use ($index) {
                $table->dropIndex($index['name']);
            });
        }
    }

    /**
     * Same driver-aware existence check used by the P0 performance-index
     * migration and the reviews.order_id unique-constraint migration — no
     * doctrine/dbal in this app (Laravel 11+ dropped the hard dependency),
     * so Schema::hasIndex() isn't available.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        return match ($driver) {
            'mysql' => DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $indexName)
                ->exists(),
            'pgsql' => DB::table('pg_indexes')
                ->where('tablename', $table)
                ->where('indexname', $indexName)
                ->exists(),
            'sqlite' => collect(DB::select("PRAGMA index_list(\"{$table}\")"))
                ->pluck('name')
                ->contains($indexName),
            default => false,
        };
    }
};
