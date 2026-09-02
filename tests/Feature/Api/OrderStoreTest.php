<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    private function fakeModemPayCheckout(): void
    {
        Http::fake([
            '*/v1/payments' => Http::response([
                'status' => true,
                'message' => 'ok',
                'data' => [
                    'payment_intent_id' => 'pi_' . uniqid(),
                    'intent_secret' => 'int_' . uniqid(),
                    'payment_link' => 'https://pay.modempay.com/checkout/int_' . uniqid(),
                    'amount' => '1000',
                    'currency' => 'GMD',
                    'expires_at' => now()->addMinutes(30)->toISOString(),
                    'status' => 'requires_payment_method',
                ],
            ], 201),
        ]);
    }

    public function test_guest_cannot_create_an_order(): void
    {
        // Task 12 gap-filler — auth:sanctum already enforces this (order
        // creation is exactly the boundary anonymous checkout is designed
        // to require sign-in at); this locks in the regression. No
        // ModemPay fake needed — the request must never reach that far.
        [, $product] = $this->buyerAndProduct();

        $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 1,
            'delivery_method' => 'pickup',
        ])->assertStatus(401);
    }

    public function test_pickup_order_does_not_require_delivery_address(): void
    {
        $this->fakeModemPayCheckout();
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
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonStructure(['data' => ['order_id', 'payment_link', 'status']]);

        $this->assertDatabaseHas('orders', [
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'delivery_method' => 'pickup',
            'delivery_address' => null,
            'status' => 'awaiting_payment',
            'payment_method' => 'modempay',
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
        $this->fakeModemPayCheckout();
        [$buyer, $product] = $this->buyerAndProduct();
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 2,
            'delivery_method' => 'farmer_delivery',
            'delivery_deadline' => now()->addDays(3)->toDateString(),
            'delivery_address' => 'House 14, Kairaba Avenue, Serrekunda',
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);

        $this->assertDatabaseHas('orders', [
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'delivery_method' => 'farmer_delivery',
            'delivery_address' => 'House 14, Kairaba Avenue, Serrekunda',
            'pickup_date' => null,
        ]);
    }

    public function test_order_creation_rolls_back_if_modempay_checkout_fails(): void
    {
        Http::fake(['*/v1/payments' => Http::response(['error' => 'server error'], 500)]);
        [$buyer, $product] = $this->buyerAndProduct();
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 2,
            'delivery_method' => 'pickup',
            'pickup_date' => now()->addDay()->toDateString(),
        ]);

        $response->assertStatus(500);
        // No orphaned awaiting_payment order left behind with no payment link.
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
    }
}
