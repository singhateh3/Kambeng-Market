<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the P1 validation fixes from the rate-limit audit: bulk-operation
 * array-size caps (AdminFarmerVerificationController::bulkApprove(),
 * AdminProductController::bulkDelete()), per_page caps on the four
 * endpoints that had none (OrderController::index(),
 * AdminOrderController::index(), SavedFarmerController::index(),
 * NotificationController::index()), and the new DB-level unique
 * constraint on reviews.order_id (see that migration).
 */
class P1ValidationCapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_approve_rejects_an_oversized_farmer_ids_array(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        // 101 real farmer ids — the cap (100) is what should reject this,
        // not the exists:users,id rule on each element.
        $farmerIds = User::factory()->count(101)->create(['role' => 'farmer'])->pluck('id')->all();

        $this->postJson('/api/admin/farmers/verification/bulk-approve', ['farmer_ids' => $farmerIds])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['farmer_ids']);
    }

    public function test_bulk_approve_allows_exactly_one_hundred(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $farmerIds = User::factory()->count(100)->create([
            'role' => 'farmer',
            'verification_status' => 'pending',
        ])->pluck('id')->all();

        $this->postJson('/api/admin/farmers/verification/bulk-approve', ['farmer_ids' => $farmerIds])
            ->assertStatus(200);
    }

    public function test_bulk_delete_rejects_an_oversized_product_ids_array(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $productIds = Product::factory()->count(101)->create()->pluck('id')->all();

        $this->postJson('/api/admin/products/bulk-delete', ['product_ids' => $productIds])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_ids']);
    }

    public function test_orders_index_caps_per_page_at_one_hundred(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $this->getJson('/api/orders?per_page=500')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_admin_orders_index_caps_per_page_at_one_hundred(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/orders?per_page=500')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_saved_farmers_index_caps_per_page_at_one_hundred(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $this->getJson('/api/saved-farmers?per_page=500')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_notifications_index_caps_per_page_at_one_hundred(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($user);

        $this->getJson('/api/notifications?per_page=500')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    /**
     * Proves the migration's unique index on reviews.order_id is real and
     * reachable via the exact Eloquent call OrderController::review()
     * itself makes (Review::create()) — this is the data-layer half of
     * the fix; OrderController::review()'s own app-level
     * "already reviewed" check (tested elsewhere) covers the non-race
     * case, and this covers what happens when that check is bypassed,
     * which is exactly the race it can't prevent on its own.
     */
    public function test_reviews_order_id_has_a_database_level_unique_constraint(): void
    {
        $order = Order::factory()->create(['status' => 'delivered']);
        Review::create(['order_id' => $order->id, 'user_id' => $order->buyer_id, 'rating' => 5]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Review::create(['order_id' => $order->id, 'user_id' => $order->buyer_id, 'rating' => 4]);
    }
}
