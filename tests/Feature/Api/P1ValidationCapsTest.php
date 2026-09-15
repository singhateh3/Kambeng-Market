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
 * NotificationController::index()), the new DB-level unique constraint on
 * reviews.order_id (see that migration), and — from the audit's follow-up
 * review pass — ProfileController::update()'s missing ValidationException
 * handling.
 *
 * Phase 2 adds the same per_page clamp coverage for the four admin list
 * endpoints that were still using the raw, unclamped `$request->per_page
 * ?? 20` pattern (AdminUserController, AdminProductController,
 * AdminPaymentTransactionController, AdminDisputeController) — now all on
 * App\Support\Pagination::perPage(), same as the four above.
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

    public function test_admin_users_index_caps_per_page_at_one_hundred(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users?per_page=500')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_admin_users_index_defaults_to_twenty_per_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 20);
    }

    public function test_admin_products_index_caps_per_page_at_one_hundred(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/products?per_page=500')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_admin_payment_transactions_index_caps_per_page_at_one_hundred(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/payment-transactions?per_page=500')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_admin_disputes_index_caps_per_page_at_one_hundred(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/disputes?per_page=500')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    /**
     * End-to-end regression for the App\Support\Pagination::perPage() fix
     * (see tests/Unit/PaginationTest.php for the exhaustive edge-case
     * coverage) — a garbage per_page must fall back to the default of 20,
     * not silently clamp to 1 the way the original per-controller
     * duplicated logic did.
     */
    public function test_orders_index_falls_back_to_default_per_page_on_a_non_numeric_value(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $this->getJson('/api/orders?per_page=not-a-number')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 20);
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

    /**
     * ProfileController::update() (the admin's own profile, PUT/POST
     * /api/admin/user/profile) had only a generic catch (\Exception $e),
     * so a validation failure — 'name' is required — fell through to a
     * 500 instead of the standard 422 shape every other validated
     * endpoint in this app returns.
     */
    public function test_admin_profile_update_returns_a_validation_error_not_a_server_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/user/profile', ['phone' => '+2207000000']) // no 'name'
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }
}
