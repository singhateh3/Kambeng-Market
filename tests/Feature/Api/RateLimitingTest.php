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
 * browsing/detail, the public farmer-profile endpoint, and POST
 * /api/products (AppServiceProvider::configureRateLimiting()). Each test
 * method gets a fresh application instance (and therefore a fresh 'array'
 * cache store — see phpunit.xml's CACHE_STORE), so counts never leak
 * between tests.
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

    public function test_public_statistics_is_throttled_per_ip_beyond_sixty_per_minute(): void
    {
        // Phase 1 P0 fix — this endpoint previously had no limiter at all.
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/public/statistics')->assertStatus(200);
        }

        $this->getJson('/api/public/statistics')
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    // ------------------------------------------------------------------
    // Phase 2 — the four remaining endpoints the audit found with no
    // limiter at all.
    // ------------------------------------------------------------------

    public function test_refresh_token_is_throttled_per_user_beyond_five_per_minute(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/user/refresh-token')->assertStatus(200);
        }

        $this->postJson('/api/user/refresh-token')
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_refresh_token_limit_is_independent_per_user(): void
    {
        $userA = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($userA);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/user/refresh-token')->assertStatus(200);
        }
        // User A is now throttled.
        $this->postJson('/api/user/refresh-token')->assertStatus(429);

        // User B's own limit is untouched by user A's usage — key is the
        // authenticated user, not shared/global or IP-based.
        $userB = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($userB);
        $this->postJson('/api/user/refresh-token')->assertStatus(200);
    }

    public function test_verification_request_is_throttled_per_user_beyond_five_per_hour(): void
    {
        // 'rejected' (rather than the factory default 'pending') so the
        // first call actually reaches the success path instead of
        // immediately hitting requestVerification()'s own "already
        // pending" 422 — irrelevant to the throttle itself (every request
        // counts against the limit regardless of the app-level outcome),
        // but makes the sequence of responses meaningful to read.
        $farmer = User::factory()->create(['role' => 'farmer', 'verification_status' => 'rejected']);
        Sanctum::actingAs($farmer);

        for ($i = 0; $i < 5; $i++) {
            $status = $this->postJson('/api/farmer/request-verification')->status();
            $this->assertNotSame(429, $status);
        }

        $this->postJson('/api/farmer/request-verification')
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_verification_request_limit_is_shared_across_both_routes(): void
    {
        // POST /farmer/profile/verify and POST /farmer/request-verification
        // both resolve to the same underlying action (see
        // FarmerProfileController::submitVerification()) — one shared
        // per-user bucket across both, not two independent 5/hour
        // allowances for what is really one action reachable two ways.
        $farmer = User::factory()->create(['role' => 'farmer', 'verification_status' => 'rejected']);
        Sanctum::actingAs($farmer);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/farmer/request-verification')->assertStatus($i === 0 ? 200 : 422);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/farmer/profile/verify')->assertStatus(422);
        }

        // 5 combined requests already spent — the 6th, via the other
        // route again, is throttled.
        $this->postJson('/api/farmer/profile/verify')
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_verification_request_limit_is_independent_per_user(): void
    {
        $farmerA = User::factory()->create(['role' => 'farmer', 'verification_status' => 'rejected']);
        Sanctum::actingAs($farmerA);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/farmer/request-verification');
        }
        $this->postJson('/api/farmer/request-verification')->assertStatus(429);

        $farmerB = User::factory()->create(['role' => 'farmer', 'verification_status' => 'rejected']);
        Sanctum::actingAs($farmerB);
        $this->postJson('/api/farmer/request-verification')->assertStatus(200);
    }

    public function test_reset_password_is_throttled_per_ip_beyond_ten_per_hour(): void
    {
        // Unauthenticated — an invalid token is enough to exercise the
        // route/limiter; the throttle middleware runs before validation
        // either way, so a real token isn't needed to prove the limit.
        $payload = [
            'token' => 'invalid-token',
            'email' => 'nobody@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/reset-password', $payload)->assertStatus(422);
        }

        $this->postJson('/reset-password', $payload)
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_reset_password_limit_is_independent_per_ip(): void
    {
        $payload = [
            'token' => 'invalid-token',
            'email' => 'nobody@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/reset-password', $payload, ['REMOTE_ADDR' => '10.0.0.1']);
        }
        $this->postJson('/reset-password', $payload, ['REMOTE_ADDR' => '10.0.0.1'])->assertStatus(429);

        // A different source IP has its own, untouched bucket.
        $this->postJson('/reset-password', $payload, ['REMOTE_ADDR' => '10.0.0.2'])->assertStatus(422);
    }

    private function validProductPayload(): array
    {
        // No 'photos' key — ProductController::store()'s Cloudinary upload
        // path only runs when photos are actually present in the request
        // (see ProductStoreTest.php, which already exercises product
        // creation with no photos and no Cloudinary mocking needed), so
        // this stays isolated from any external service without faking one.
        return [
            'name' => 'Fresh Tomatoes',
            'category' => 'Vegetables',
            'quantity' => 20,
            'unit' => 'kg',
            'price' => 15.50,
            'harvest_date' => now()->subDay()->toDateString(),
            'expiry_date' => now()->addDays(10)->toDateString(),
            'description' => 'Vine-ripened tomatoes, picked this morning.',
        ];
    }

    public function test_product_creation_is_throttled_per_user_after_ten_per_minute(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($farmer);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/products', $this->validProductPayload())->assertStatus(201);
        }

        $response = $this->postJson('/api/products', $this->validProductPayload());

        $response->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_product_creation_limit_is_independent_per_farmer(): void
    {
        $farmerA = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($farmerA);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/products', $this->validProductPayload())->assertStatus(201);
        }
        // Farmer A is now throttled.
        $this->postJson('/api/products', $this->validProductPayload())->assertStatus(429);

        // Farmer B's own limit is untouched by farmer A's usage — same
        // user-keyed isolation already proven for orders-create above.
        $farmerB = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($farmerB);
        $this->postJson('/api/products', $this->validProductPayload())->assertStatus(201);
    }
}
