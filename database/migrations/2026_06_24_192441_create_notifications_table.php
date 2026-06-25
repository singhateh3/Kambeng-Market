<?php

// database/migrations/xxxx_xx_xx_create_notifications_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type'); // order_placed, order_confirmed, order_shipped, order_delivered, order_cancelled, farmer_verified, etc.
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable(); // Additional data like order_id, product_id, etc.
            $table->string('icon')->nullable(); // Emoji or icon class
            $table->string('link')->nullable(); // URL to navigate when clicked
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};