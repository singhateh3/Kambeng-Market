<?php

// database/migrations/2026_09_10_002651_add_performance_indexes_to_products_and_orders_tables.php
//
// Indexes for the hot filter/sort columns identified in the performance
// audit: products.status (active/sold filtering on every browse request),
// orders.status (dashboard/statistics counts), products.created_at
// (default "newest first" sort), orders.order_date (sales history sort).
// Existing primary/foreign keys are untouched.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        ['table' => 'products', 'column' => 'status', 'name' => 'products_status_index'],
        ['table' => 'products', 'column' => 'created_at', 'name' => 'products_created_at_index'],
        ['table' => 'orders', 'column' => 'status', 'name' => 'orders_status_index'],
        ['table' => 'orders', 'column' => 'order_date', 'name' => 'orders_order_date_index'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index) {
            if ($this->indexExists($index['table'], $index['name'])) {
                continue;
            }

            Schema::table($index['table'], function (Blueprint $table) use ($index) {
                $table->index($index['column'], $index['name']);
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
     * No doctrine/dbal in this app (Laravel 11+ dropped the hard
     * dependency), so index existence is checked directly against each
     * driver's catalog rather than via Schema::hasIndex().
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
