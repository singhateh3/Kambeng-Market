<?php

namespace Database\Factories;

use App\Models\SavedFarmer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedFarmer>
 */
class SavedFarmerFactory extends Factory
{
    protected $model = SavedFarmer::class;

    public function definition(): array
    {
        return [
            'buyer_id' => User::factory()->state(['role' => 'buyer']),
            'farmer_id' => User::factory()->state(['role' => 'farmer']),
        ];
    }
}
