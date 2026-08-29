<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'farmer_id' => User::factory()->state(['role' => 'farmer']),
            'name' => fake()->words(2, true),
            'category' => fake()->randomElement(['vegetables', 'fruits', 'grains']),
            'variety' => null,
            'quantity' => fake()->randomFloat(2, 1, 100),
            'unit' => 'kg',
            'price' => fake()->randomFloat(2, 1, 500),
            'harvest_date' => now()->subDays(2)->toDateString(),
            'expiry_date' => now()->addDays(14)->toDateString(),
            'photos' => [],
            'description' => fake()->sentence(),
            'status' => 'active',
            'views_count' => 0,
        ];
    }
}
