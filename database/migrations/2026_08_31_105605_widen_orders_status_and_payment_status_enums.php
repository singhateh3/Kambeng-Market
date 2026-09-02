<?php

// database/migrations/2026_08_31_105605_widen_orders_status_and_payment_status_enums.php
//
// Widens three existing enum columns on `orders` for the ModemPay payment
// architecture:
//   - status:         + 'awaiting_payment' (an order isn't real/farmer-visible
//                      until a verified successful payment webhook fires)
//   - payment_method:  + 'modempay' ('cod' kept only for grandfathered
//                      pre-cutover orders, never selectable for new ones)
//   - payment_status:  + 'processing', 'expired', 'partially_refunded'
//
// Deliberately does NOT add 'payment_failed'/'expired' to `status` — those
// belong to payment_status only, per the explicit correction that order
// status and payment status must stay strictly separate. A checkout that
// fails or expires moves `status` to the existing 'cancelled' (nothing
// fulfillment-wise is happening) while `payment_status` records why.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUS_VALUES = ['awaiting_payment', 'pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];
    private const PAYMENT_METHOD_VALUES = ['cod', 'modempay'];
    private const PAYMENT_STATUS_VALUES = ['pending', 'processing', 'paid', 'failed', 'expired', 'cancelled', 'refunded', 'partially_refunded'];

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $this->mysqlWiden();
        } elseif ($driver === 'pgsql') {
            $this->pgsqlWiden(self::STATUS_VALUES, self::PAYMENT_METHOD_VALUES, self::PAYMENT_STATUS_VALUES);
        } elseif ($driver === 'sqlite') {
            $this->sqliteWiden();
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        // Narrowing back requires no row currently uses one of the new
        // values — Laravel migrations don't run down() in production
        // practice here (this app has never rolled one back outside local
        // dev), but this keeps `down()` honest rather than a no-op.
        $originalStatus = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];
        $originalPaymentMethod = ['cod'];
        $originalPaymentStatus = ['pending', 'paid', 'failed', 'cancelled', 'refunded'];

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY status ENUM('" . implode("','", $originalStatus) . "') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE orders MODIFY payment_method ENUM('" . implode("','", $originalPaymentMethod) . "') NOT NULL DEFAULT 'cod'");
            DB::statement("ALTER TABLE orders MODIFY payment_status ENUM('" . implode("','", $originalPaymentStatus) . "') NOT NULL DEFAULT 'pending'");
        } elseif ($driver === 'pgsql') {
            $this->pgsqlWiden($originalStatus, $originalPaymentMethod, $originalPaymentStatus);
        } elseif ($driver === 'sqlite') {
            $this->sqliteWiden($originalStatus, $originalPaymentMethod, $originalPaymentStatus);
        }
    }

    private function mysqlWiden(): void
    {
        DB::statement("ALTER TABLE orders MODIFY status ENUM('" . implode("','", self::STATUS_VALUES) . "') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE orders MODIFY payment_method ENUM('" . implode("','", self::PAYMENT_METHOD_VALUES) . "') NOT NULL DEFAULT 'cod'");
        DB::statement("ALTER TABLE orders MODIFY payment_status ENUM('" . implode("','", self::PAYMENT_STATUS_VALUES) . "') NOT NULL DEFAULT 'pending'");
    }

    /**
     * Postgres: Laravel's enum() generates a plain varchar column plus a
     * named CHECK constraint. Rather than assume the constraint name (no
     * local Postgres instance exists to verify it against), look it up
     * from the system catalog and drop exactly that one.
     */
    private function pgsqlWiden(array $status, array $paymentMethod, array $paymentStatus): void
    {
        foreach (['status' => $status, 'payment_method' => $paymentMethod, 'payment_status' => $paymentStatus] as $column => $values) {
            $constraint = DB::selectOne(
                "SELECT conname FROM pg_constraint c
                 JOIN pg_attribute a ON a.attnum = ANY(c.conkey) AND a.attrelid = c.conrelid
                 WHERE c.conrelid = 'orders'::regclass AND c.contype = 'c' AND a.attname = ?",
                [$column]
            );

            if ($constraint) {
                DB::statement('ALTER TABLE orders DROP CONSTRAINT ' . $constraint->conname);
            }

            $quoted = implode(',', array_map(fn ($v) => "'" . $v . "'", $values));
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_{$column}_check CHECK ({$column} IN ({$quoted}))");
        }
    }

    /**
     * SQLite: CHECK constraints can't be altered in place, only rebuilt.
     * This drop+recreate is only safe because SQLite is exclusively this
     * app's test-suite driver (see phpunit.xml) — every migration run
     * starts from a fresh, empty :memory: database, so there is never
     * real data to preserve here. This must never be applied this way to
     * a driver holding real rows (mysql/pgsql use non-destructive ALTERs
     * above).
     */
    private function sqliteWiden(?array $status = null, ?array $paymentMethod = null, ?array $paymentStatus = null): void
    {
        $status ??= self::STATUS_VALUES;
        $paymentMethod ??= self::PAYMENT_METHOD_VALUES;
        $paymentStatus ??= self::PAYMENT_STATUS_VALUES;

        $rebuild = function (string $column, array $values, string $default) {
            DB::statement("ALTER TABLE orders DROP COLUMN {$column}");
            $quoted = implode(',', array_map(fn ($v) => "'" . $v . "'", $values));
            DB::statement("ALTER TABLE orders ADD COLUMN {$column} TEXT CHECK ({$column} IN ({$quoted})) NOT NULL DEFAULT '{$default}'");
        };

        $rebuild('status', $status, 'pending');
        $rebuild('payment_method', $paymentMethod, 'cod');
        $rebuild('payment_status', $paymentStatus, 'pending');
    }
};
