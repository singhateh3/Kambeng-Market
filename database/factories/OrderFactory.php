<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'buyer_id' => User::factory()->state(['role' => 'buyer']),
            'product_id' => Product::factory(),
            'quantity' => fake()->randomFloat(2, 1, 10),
            'total_price' => fake()->randomFloat(2, 10, 1000),
            'status' => 'pending',
            'special_instructions' => null,
            'delivery_method' => 'pickup',
            'delivery_deadline' => null,
            'pickup_date' => now()->addDays(3)->toDateString(),
            'order_date' => now(),
        ];
    }
}
