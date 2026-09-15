<?php

// tests/Feature/Api/PublicStatisticsTest.php
//
// Covers the Phase 1 P0 audit fix to GET /public/statistics
// (PublicController::statistics()): it now uses the same Cache::remember
// pattern as AdminDashboardController/FarmerProfileController (via
// DashboardCache) instead of running its 4 aggregate queries on every
// request, and is invalidated by the Order/Product/User/Review model
// events registered in AppServiceProvider::configureDashboardCacheInvalidation().
// The 60/min/IP rate limiter is covered separately in RateLimitingTest.php,
// alongside the pre-existing public-products/public-farmer-profile
// limiter tests it must not disturb.

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Support\DashboardCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_is_publicly_accessible_with_the_expected_shape(): void
    {
        $response = $this->getJson('/api/public/statistics');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'products' => ['active'],
                    'users' => ['farmers'],
                    'orders' => ['total'],
                    'reviews' => ['average_rating'],
                ],
            ]);
    }

    public function test_response_is_served_from_cache_on_repeated_requests(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);

        $this->assertFalse(Cache::has(DashboardCache::publicKey()));

        $this->getJson('/api/public/statistics')->assertStatus(200);

        // The cache entry exists after the first request...
        $this->assertTrue(Cache::has(DashboardCache::publicKey()));

        // ...and a product inserted directly (bypassing Eloquent model
        // events entirely, so no invalidation fires) is NOT reflected on
        // the next request — proving that request is actually served from
        // cache rather than recomputed.
        DB::table('products')->insert([
            'farmer_id' => $farmer->id,
            'name' => 'Test Product',
            'category' => 'Vegetables',
            'quantity' => 10,
            'unit' => 'kg',
            'price' => 5,
            'harvest_date' => now()->subDay(),
            'expiry_date' => now()->addDays(5),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cached = $this->getJson('/api/public/statistics')->assertStatus(200)->json('data.products.active');
        $this->assertSame(0, $cached);
    }

    public function test_new_order_invalidates_the_cache(): void
    {
        $product = Product::factory()->create();

        $before = $this->getJson('/api/public/statistics')->assertStatus(200)->json('data.orders.total');

        Order::factory()->create(['product_id' => $product->id]);

        $after = $this->getJson('/api/public/statistics')->assertStatus(200)->json('data.orders.total');

        $this->assertSame($before + 1, $after);
    }

    public function test_new_product_invalidates_the_cache(): void
    {
        $before = $this->getJson('/api/public/statistics')->assertStatus(200)->json('data.products.active');

        Product::factory()->create(['status' => 'active', 'expiry_date' => now()->addDays(5)]);

        $after = $this->getJson('/api/public/statistics')->assertStatus(200)->json('data.products.active');

        $this->assertSame($before + 1, $after);
    }

    public function test_new_farmer_invalidates_the_cache(): void
    {
        $before = $this->getJson('/api/public/statistics')->assertStatus(200)->json('data.users.farmers');

        User::factory()->create(['role' => 'farmer']);

        $after = $this->getJson('/api/public/statistics')->assertStatus(200)->json('data.users.farmers');

        $this->assertSame($before + 1, $after);
    }

    public function test_new_review_invalidates_the_cache(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);

        // json_encode() drops the ".0" on a whole-number float, so this
        // decodes as an int (0) rather than a float — assertEquals (loose)
        // rather than assertSame is the correct comparison here.
        $before = $this->getJson('/api/public/statistics')->assertStatus(200)->json('data.reviews.average_rating');
        $this->assertEquals(0, $before);

        Review::create(['order_id' => $order->id, 'user_id' => $buyer->id, 'rating' => 5]);

        $after = $this->getJson('/api/public/statistics')->assertStatus(200)->json('data.reviews.average_rating');
        $this->assertEquals(5.0, $after);
    }
}
