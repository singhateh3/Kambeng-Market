<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductStoreTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Fresh Tomatoes',
            'category' => 'Vegetables',
            'quantity' => 20,
            'unit' => 'kg',
            'price' => 15.50,
            'harvest_date' => now()->subDay()->toDateString(),
            'expiry_date' => now()->addDays(10)->toDateString(),
            'description' => 'Vine-ripened tomatoes, picked this morning.',
        ], $overrides);
    }

    public function test_description_is_persisted_and_returned_on_create(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($farmer);

        $response = $this->postJson('/api/products', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.description', 'Vine-ripened tomatoes, picked this morning.');

        $this->assertDatabaseHas('products', [
            'name' => 'Fresh Tomatoes',
            'farmer_id' => $farmer->id,
            'description' => 'Vine-ripened tomatoes, picked this morning.',
        ]);
    }

    public function test_description_is_optional(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($farmer);

        $response = $this->postJson('/api/products', $this->validPayload(['description' => null]));

        $response->assertStatus(201)
            ->assertJsonPath('data.description', null);
    }
}
