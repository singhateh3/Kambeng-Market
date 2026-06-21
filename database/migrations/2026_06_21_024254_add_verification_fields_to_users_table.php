<?php

// database/migrations/2026_06_21_024254_add_verification_fields_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Check if column exists before adding
            if (!Schema::hasColumn('users', 'verification_requested_at')) {
                $table->timestamp('verification_requested_at')->nullable()->after('verified_at');
            }
            
            if (!Schema::hasColumn('users', 'verification_status')) {
                $table->enum('verification_status', ['pending', 'approved', 'rejected'])->default('pending')->after('verification_requested_at');
            }
            
            // Note: verified_at already exists in the users table migration
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['verification_requested_at', 'verification_status']);
        });
    }
};