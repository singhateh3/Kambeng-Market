<?php

namespace Tests\Feature\Api;

use App\Models\FarmerProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the Cache::remember() added to AdminDashboardController::statistics()
 * (and, in the P1 pass, ::chartData() too) and FarmerProfileController::
 * statistics(), and the Order/Product/User model-event invalidation
 * registered in AppServiceProvider::configureDashboardCacheInvalidation().
 */
class DashboardStatisticsCachingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_statistics_reflect_a_new_order_after_cache_invalidation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);

        Sanctum::actingAs($admin);

        $before = $this->getJson('/api/admin/dashboard/statistics')
            ->assertStatus(200)
            ->json('data.orders.total');

        Order::factory()->create(['product_id' => $product->id]);

        // Without invalidation this would still read the pre-order count
        // back from cache instead of the fresh total.
        $after = $this->getJson('/api/admin/dashboard/statistics')
            ->assertStatus(200)
            ->json('data.orders.total');

        $this->assertSame($before + 1, $after);
    }

    public function test_admin_charts_reflect_a_new_order_after_cache_invalidation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::factory()->create();

        Sanctum::actingAs($admin);

        $today = now()->toDateString();

        $before = $this->getJson('/api/admin/dashboard/charts')
            ->assertStatus(200)
            ->json('data.daily_orders');
        $beforeCount = collect($before)->firstWhere('date', $today)['count'] ?? 0;

        Order::factory()->create(['product_id' => $product->id, 'created_at' => now()]);

        // Without invalidation (DashboardCache::forgetAdmin() now clears
        // both the statistics and charts cache entries together — see
        // that method) this would still read the pre-order count back
        // from cache instead of the fresh total.
        $after = $this->getJson('/api/admin/dashboard/charts')
            ->assertStatus(200)
            ->json('data.daily_orders');
        $afterCount = collect($after)->firstWhere('date', $today)['count'] ?? 0;

        $this->assertSame($beforeCount + 1, $afterCount);
    }

    public function test_farmer_statistics_are_cached_per_farmer_and_invalidated_on_new_order(): void
    {
        $farmerA = User::factory()->create(['role' => 'farmer']);
        FarmerProfile::factory()->create(['user_id' => $farmerA->id]);
        $productA = Product::factory()->create(['farmer_id' => $farmerA->id]);

        $farmerB = User::factory()->create(['role' => 'farmer']);
        FarmerProfile::factory()->create(['user_id' => $farmerB->id]);

        Sanctum::actingAs($farmerA);
        $this->getJson('/api/farmer/profile/statistics')
            ->assertStatus(200)
            ->assertJsonPath('data.total_orders', 0);

        Order::factory()->create(['product_id' => $productA->id]);

        $this->getJson('/api/farmer/profile/statistics')
            ->assertStatus(200)
            ->assertJsonPath('data.total_orders', 1);

        // A different farmer's cached statistics are a separate cache
        // entry — never leaked/shared across users.
        Sanctum::actingAs($farmerB);
        $this->getJson('/api/farmer/profile/statistics')
            ->assertStatus(200)
            ->assertJsonPath('data.total_orders', 0);
    }
}
