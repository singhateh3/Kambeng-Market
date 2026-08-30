<?php

// database/migrations/2026_08_30_230140_create_saved_farmers_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_farmers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('farmer_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // A save is binary (exists or doesn't) — unlike disputes, there's
            // no status to scope the uniqueness against, so a plain composite
            // unique index works identically on every driver.
            $table->unique(['buyer_id', 'farmer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_farmers');
    }
};
