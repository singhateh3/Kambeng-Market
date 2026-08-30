<?php

namespace Database\Factories;

use App\Models\FarmerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FarmerProfile>
 */
class FarmerProfileFactory extends Factory
{
    protected $model = FarmerProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'farmer']),
            'farm_name' => fake()->company(),
            'farm_location' => fake()->city(),
            'bio' => fake()->sentence(),
            'id_verified' => false,
        ];
    }
}
