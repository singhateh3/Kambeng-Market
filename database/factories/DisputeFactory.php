<?php

namespace Database\Factories;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispute>
 */
class DisputeFactory extends Factory
{
    protected $model = Dispute::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'reported_by' => User::factory()->state(['role' => 'buyer']),
            'reason' => fake()->randomElement(Dispute::REASONS),
            'description' => fake()->sentence(),
            'status' => 'open',
            'admin_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }
}
