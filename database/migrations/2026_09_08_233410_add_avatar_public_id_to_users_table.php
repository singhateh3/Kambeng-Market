<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `avatar` (nullable string) already exists on users since the initial
// migration and holds either a legacy local-disk relative path or, going
// forward, a Cloudinary secure_url — see User::getAvatarUrlAttribute().
// This adds only the Cloudinary public_id alongside it, so an avatar
// replaced/removed later can be deleted from Cloudinary by exact id instead
// of regexing it back out of the URL (see ProductController::deletePhotos()
// for why that's fragile — the fix here is deliberately not reusing that
// pattern). Nullable and additive: existing rows are untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_public_id')->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_public_id');
        });
    }
};
