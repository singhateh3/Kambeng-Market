<?php

// database/migrations/2026_09_17_000000_add_deactivation_fields_to_users_and_products_tables.php
//
// Phase 3B — account deactivation/anonymization. Two new, independent
// nullable columns:
//
//   - users.deactivated_at: the sole authoritative gate for "can this
//     account authenticate/use the API right now" (see
//     EnsureAccountIsActive middleware, AuthController::login(),
//     SocialAuthController::handle()). Deliberately a NEW column rather
//     than reusing the existing users.is_active — that column already has
//     a separate, currently-unenforced meaning (AdminUserController::
//     toggleStatus()'s temporary suspend/reactivate toggle), and entangling
//     the two would either silently start enforcing suspensions too (an
//     unrelated behavior change) or require redefining what is_active
//     means. Both default to leaving every existing row unaffected
//     (nullable, no default), so this migration changes nothing about
//     current behavior until a user is actually deactivated.
//
//   - products.delisted_at: lets a farmer's product be hidden from public
//     marketplace discovery (Product::scopeActive()) without deleting the
//     row — deleting it would cascade into orders.product_id and destroy
//     order/payment history for that product. The existing products.status
//     enum (active/sold — see create_products_table) has no way to
//     represent "hidden because the farmer departed" without redefining
//     what 'sold' means, so this is a separate, additive signal instead of
//     a third enum value.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('delisted_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('delisted_at');
        });
    }
};
