<?php

// database/migrations/2026_06_21_024303_add_verification_fields_to_farmer_profiles_table.php

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
        Schema::table('farmer_profiles', function (Blueprint $table) {
            // Check if column exists before adding
            if (!Schema::hasColumn('farmer_profiles', 'verification_document')) {
                $table->string('verification_document')->nullable()->after('id_verified');
            }
            
            if (!Schema::hasColumn('farmer_profiles', 'verification_notes')) {
                $table->text('verification_notes')->nullable()->after('verification_document');
            }
            
            if (!Schema::hasColumn('farmer_profiles', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('verification_notes');
            }
            
            if (!Schema::hasColumn('farmer_profiles', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_at');
            }
            
            if (!Schema::hasColumn('farmer_profiles', 'business_license')) {
                $table->string('business_license')->nullable()->after('rejection_reason');
            }
            
            if (!Schema::hasColumn('farmer_profiles', 'id_document')) {
                $table->string('id_document')->nullable()->after('business_license');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('farmer_profiles', function (Blueprint $table) {
            $columns = [
                'verification_document',
                'verification_notes',
                'rejected_at',
                'rejection_reason',
                'business_license',
                'id_document'
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('farmer_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};