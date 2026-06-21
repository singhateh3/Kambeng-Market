<?php

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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farmer_id')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->string('category');
            $table->decimal('quantity', 10, 2);
            $table->string('unit'); // kg, bunch, pile, bag
            $table->decimal('price', 10, 2);
            $table->date('harvest_date');
            $table->date('expiry_date');
            $table->json('photos')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'sold'])->default('active');
            $table->integer('views_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};