<?php

// database/migrations/xxxx_xx_xx_xxxxxx_add_pickup_date_to_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'pickup_date')) {
                $table->date('pickup_date')->nullable()->after('delivery_deadline');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'pickup_date')) {
                $table->dropColumn('pickup_date');
            }
        });
    }
};