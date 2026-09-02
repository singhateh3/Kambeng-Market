<?php

// database/migrations/2026_09_02_134519_add_social_auth_fields_to_users_table.php
//
// Task 12 — Google/Apple Sign-In. Adds:
//   - provider / provider_id: which social identity (if any) is linked to
//     this account, and that provider's stable subject id. A composite
//     unique index prevents the same provider identity ever being linked
//     to two different accounts. NULL/NULL (the default, for every
//     existing password-based user) doesn't collide with itself under a
//     unique index on MySQL, PostgreSQL, or SQLite — all three treat NULL
//     as distinct from every other NULL for uniqueness purposes, so this
//     is safe across every DB driver this app runs on (see phpunit.xml /
//     production config).
//   - password becomes nullable: a social-only account (no password ever
//     set) must be representable. Existing password-based flows are
//     unaffected — SocialAuthController is the only place that creates a
//     user with a null password; AuthController::register() still
//     requires one via RegisterUserRequest's validation, unchanged.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('role');
            $table->string('provider_id')->nullable()->after('provider');
            $table->unique(['provider', 'provider_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_id']);
            $table->dropColumn(['provider', 'provider_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
