<?php

namespace Tests\Feature\Api;

use App\Models\FarmerProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the throttling added for POST /api/orders, public product
 * browsing/detail, and the public farmer-profile endpoint
 * (AppServiceProvider::configureRateLimiting()). Each test method gets a
 * fresh application instance (and therefore a fresh 'array' cache store —
 * see phpunit.xml's CACHE_STORE), so counts never leak between tests.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeModemPayCheckout(): void
    {
        // A closure response (not a static array) so every order in the
        // throttle-count loop gets a unique payment_intent_id — a fixed
        // value would collide on payment_transactions.modempay_reference's
        // unique constraint after the first order.
        Http::fake(fn () => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'payment_intent_id' => 'pi_' . uniqid('', true),
                'intent_secret' => 'int_' . uniqid('', true),
                'payment_link' => 'https://pay.modempay.com/checkout/int_' . uniqid('', true),
                'amount' => '1000',
                'currency' => 'GMD',
                'expires_at' => now()->addMinutes(30)->toISOString(),
                'status' => 'requires_payment_method',
            ],
        ], 201));
    }

    public function test_order_creation_is_throttled_per_user_after_ten_per_minute(): void
    {
        $this->fakeModemPayCheckout();

        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        for ($i = 0; $i < 10; $i++) {
            $product = Product::factory()->create(['quantity' => 50]);

            $this->postJson('/api/orders', [
                'product_id' => $product->id,
                'quantity' => 1,
                'delivery_method' => 'pickup',
                'pickup_date' => now()->addDay()->toDateString(),
            ])->assertStatus(201);
        }

        $product = Product::factory()->create(['quantity' => 50]);

        $response = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 1,
            'delivery_method' => 'pickup',
            'pickup_date' => now()->addDay()->toDateString(),
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
        $response->assertHeader('Retry-After');
    }

    public function test_product_browsing_is_throttled_per_ip_beyond_one_hundred_per_minute(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->getJson('/api/products')->assertStatus(200);
        }

        $this->getJson('/api/products')
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_public_farmer_profile_is_throttled_per_ip_beyond_sixty_per_minute(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        FarmerProfile::factory()->create(['user_id' => $farmer->id]);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson("/api/farmers/{$farmer->id}/profile")->assertStatus(200);
        }

        $this->getJson("/api/farmers/{$farmer->id}/profile")
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }
}
