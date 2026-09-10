<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the P1 rate limiters added after the P0 pass — see
 * AppServiceProvider::configureRateLimiting() (everything below the "P1
 * hardening" marker) and their routes in routes/api.php. None of the P0
 * limiters (orders-create, public-products, public-farmer-profile,
 * modempay-webhook, login/register/forgot-password/social-login) are
 * touched or retested here — see RateLimitingTest.php for those.
 *
 * Each test method gets a fresh application instance (and therefore a
 * fresh 'array' cache store — see phpunit.xml's CACHE_STORE), so counts
 * never leak between tests.
 */
class P1RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_is_throttled_after_twenty_per_minute(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 20; $i++) {
            $this->putJson('/api/user/profile', ['name' => "Name {$i}"])->assertStatus(200);
        }

        $this->putJson('/api/user/profile', ['name' => 'One Too Many'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_avatar_upload_is_throttled_after_ten_per_minute(): void
    {
        Storage::fake('public');
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($farmer);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/farmer/profile/avatar', [
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
            ])->assertStatus(200);
        }

        $this->postJson('/api/farmer/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ])->assertStatus(429);
    }

    public function test_products_write_is_throttled_after_thirty_per_minute(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($farmer);

        for ($i = 0; $i < 30; $i++) {
            $product = Product::factory()->create(['farmer_id' => $farmer->id, 'status' => 'active']);
            $this->patchJson("/api/products/{$product->id}/status", ['status' => 'sold'])
                ->assertStatus(200);
        }

        $product = Product::factory()->create(['farmer_id' => $farmer->id, 'status' => 'active']);
        $this->patchJson("/api/products/{$product->id}/status", ['status' => 'sold'])
            ->assertStatus(429);
    }

    public function test_order_actions_is_throttled_after_twenty_per_minute(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        Sanctum::actingAs($farmer);

        for ($i = 0; $i < 20; $i++) {
            $order = Order::factory()->create(['product_id' => $product->id, 'status' => 'pending']);
            $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])
                ->assertStatus(200);
        }

        $order = Order::factory()->create(['product_id' => $product->id, 'status' => 'pending']);
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertStatus(429);
    }

    public function test_review_create_is_throttled_after_five_per_minute(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        for ($i = 0; $i < 5; $i++) {
            $order = Order::factory()->create([
                'buyer_id' => $buyer->id,
                'status' => 'delivered',
            ]);
            $this->postJson("/api/orders/{$order->id}/review", ['rating' => 5])
                ->assertStatus(201);
        }

        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);
        $this->postJson("/api/orders/{$order->id}/review", ['rating' => 5])
            ->assertStatus(429);
    }

    public function test_notifications_write_is_throttled_after_sixty_per_minute(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($user);

        // No NotificationFactory exists in this app — create rows directly.
        $makeNotification = fn () => Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Test',
            'message' => 'Test notification',
            'is_read' => false,
        ]);

        for ($i = 0; $i < 60; $i++) {
            $notification = $makeNotification();
            $this->putJson("/api/notifications/{$notification->id}/read")->assertStatus(200);
        }

        $notification = $makeNotification();
        $this->putJson("/api/notifications/{$notification->id}/read")->assertStatus(429);
    }

    public function test_saved_farmers_write_is_throttled_after_thirty_per_minute(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        for ($i = 0; $i < 30; $i++) {
            $farmer = User::factory()->create(['role' => 'farmer']);
            $this->postJson("/api/saved-farmers/{$farmer->id}")->assertStatus(201);
        }

        $farmer = User::factory()->create(['role' => 'farmer']);
        $this->postJson("/api/saved-farmers/{$farmer->id}")->assertStatus(429);
    }

    public function test_admin_write_is_throttled_after_sixty_per_minute(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        for ($i = 0; $i < 60; $i++) {
            $user = User::factory()->create(['role' => 'buyer']);
            $this->patchJson("/api/admin/users/{$user->id}/toggle-status", ['is_active' => false])
                ->assertStatus(200);
        }

        $user = User::factory()->create(['role' => 'buyer']);
        $this->patchJson("/api/admin/users/{$user->id}/toggle-status", ['is_active' => false])
            ->assertStatus(429);
    }

    /**
     * The throttle fires regardless of business-logic outcome — same
     * philosophy as the existing login-throttle test, which uses wrong
     * passwords for the in-limit attempts. Every order here is
     * deliberately NOT payout-eligible (retryPayout requires
     * payout_status === 'failed'), so all 10 in-limit attempts get a
     * clean 422 from the controller; only the 11th is blocked by the
     * limiter itself, before the controller ever runs.
     */
    public function test_admin_financial_is_throttled_after_ten_per_minute(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::factory()->create();
        Sanctum::actingAs($admin);

        for ($i = 0; $i < 10; $i++) {
            $order = Order::factory()->create(['product_id' => $product->id]);
            $this->postJson("/api/admin/orders/{$order->id}/retry-payout")->assertStatus(422);
        }

        $order = Order::factory()->create(['product_id' => $product->id]);
        $this->postJson("/api/admin/orders/{$order->id}/retry-payout")
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }
}
