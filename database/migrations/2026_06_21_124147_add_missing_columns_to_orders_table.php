<?php

// database/migrations/xxxx_xx_xx_add_missing_columns_to_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'delivery_deadline')) {
                $table->date('delivery_deadline')->nullable()->after('delivery_method');
            }
            if (!Schema::hasColumn('orders', 'order_date')) {
                $table->timestamp('order_date')->useCurrent()->after('delivery_deadline');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_deadline', 'order_date']);
        });
    }
};