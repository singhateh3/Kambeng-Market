<?php

// database/migrations/2026_08_31_110048_add_refund_decision_to_disputes_table.php
//
// Resolving a dispute must never implicitly mean a refund — the admin
// makes an explicit financial decision alongside the status change. See
// AdminDisputeController::updateStatus().

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->enum('refund_decision', ['no_refund', 'full_refund', 'partial_refund'])
                ->nullable()
                ->after('admin_note');
            // Explicit even for full_refund — never assumed to equal the
            // order's total_price at resolution time.
            $table->decimal('refund_amount', 10, 2)->nullable()->after('refund_decision');
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropColumn(['refund_decision', 'refund_amount']);
        });
    }
};
