<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderStoreTest extends TestCase
{
    use RefreshDatabase;

    private function buyerAndProduct(): array
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $product = Product::factory()->create(['quantity' => 50]);

        return [$buyer, $product];
    }

    public function test_pickup_order_does_not_require_delivery_address(): void
    {
        [$buyer, $product] = $this->buyerAndProduct();
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 2,
            'delivery_method' => 'pickup',
            'pickup_date' => now()->addDay()->toDateString(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_method', 'pickup')
            ->assertJsonPath('data.delivery_address', null);

        $this->assertDatabaseHas('orders', [
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'delivery_method' => 'pickup',
            'delivery_address' => null,
        ]);
    }

    public function test_farmer_delivery_order_requires_delivery_address(): void
    {
        [$buyer, $product] = $this->buyerAndProduct();
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 2,
            'delivery_method' => 'farmer_delivery',
            'delivery_deadline' => now()->addDays(3)->toDateString(),
            // delivery_address intentionally omitted
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_address']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_farmer_delivery_order_persists_and_returns_delivery_address(): void
    {
        [$buyer, $product] = $this->buyerAndProduct();
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 2,
            'delivery_method' => 'farmer_delivery',
            'delivery_deadline' => now()->addDays(3)->toDateString(),
            'delivery_address' => 'House 14, Kairaba Avenue, Serrekunda',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_method', 'farmer_delivery')
            ->assertJsonPath('data.delivery_address', 'House 14, Kairaba Avenue, Serrekunda');

        $this->assertDatabaseHas('orders', [
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'delivery_method' => 'farmer_delivery',
            'delivery_address' => 'House 14, Kairaba Avenue, Serrekunda',
            'pickup_date' => null,
        ]);
    }
}
