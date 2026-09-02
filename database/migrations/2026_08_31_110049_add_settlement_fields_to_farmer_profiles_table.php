<?php

// database/migrations/2026_08_31_110049_add_settlement_fields_to_farmer_profiles_table.php
//
// Where a farmer's payout is sent via ModemPay's Transfer API. Read fresh
// at the moment of payout release (not locked in at order creation) — but
// every actual transfer snapshots the exact values it used into that
// payment_transactions row's metadata, so a later profile change can never
// alter a historical payout record. See PayoutReleaseService.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmer_profiles', function (Blueprint $table) {
            // Only two networks exist on ModemPay in Gambia — confirmed
            // directly against their docs, not assumed.
            $table->enum('settlement_network', ['wave', 'afrimoney'])->nullable();
            $table->string('settlement_account_number')->nullable();
            $table->string('settlement_beneficiary_name')->nullable();
            // Kambeng's own confirmation step — ModemPay itself performs no
            // recipient-side verification before a transfer.
            $table->timestamp('settlement_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('farmer_profiles', function (Blueprint $table) {
            $table->dropColumn(['settlement_network', 'settlement_account_number', 'settlement_beneficiary_name', 'settlement_verified_at']);
        });
    }
};
