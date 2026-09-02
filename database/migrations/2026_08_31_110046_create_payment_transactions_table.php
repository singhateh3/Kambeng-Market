<?php

// database/migrations/2026_08_31_110046_create_payment_transactions_table.php
//
// The financial ledger. Append-only by convention (no code path should ever
// UPDATE or DELETE a row here — a correction is always a new row of a
// different type, e.g. a failed payout attempt is followed by a fresh
// 'payout' row for the retry, never a mutation of the failed one). This is
// the source of truth; `orders.commission_amount`/`farmer_net_amount` are
// snapshots of *intent*, but the actual amounts ever charged/refunded/paid
// are always what's summed from this table.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['charge', 'refund', 'partial_refund', 'payout', 'payout_reversal']);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('GMD');
            // Only meaningful on 'charge'/'refund'/'partial_refund' rows —
            // the commission portion of this specific amount.
            $table->decimal('commission_amount', 10, 2)->nullable();
            $table->enum('status', ['pending', 'succeeded', 'failed'])->default('pending');
            // ModemPay's own id for this specific operation (payment intent
            // id, transfer id, etc.) — nullable because a 'pending' row may
            // be written before ModemPay has returned one yet.
            $table->string('modempay_reference')->nullable();
            // The Idempotency-Key WE sent — stored so a retry of the same
            // logical operation reuses it rather than generating a new one,
            // which is what actually prevents a duplicate transfer.
            $table->string('idempotency_key');
            // For 'payout' rows: an immutable snapshot of exactly which
            // settlement network/account/beneficiary-name was used for
            // *this* transfer, taken at the moment it was created — a
            // later change to the farmer's profile must never alter this
            // historical record. Also holds the raw webhook payload for
            // forensic/debugging purposes.
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['order_id', 'type']);
        });

        // modempay_reference is unique only once populated (multiple NULLs
        // are fine — a 'pending' row written before ModemPay responds).
        // Handled as a plain nullable-safe unique index; every driver this
        // app uses (mysql/pgsql/sqlite) treats multiple NULLs as
        // non-conflicting under a standard unique index.
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->unique('modempay_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
