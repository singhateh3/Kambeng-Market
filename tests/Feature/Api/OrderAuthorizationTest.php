<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers Task 10 — OrderPolicy consolidation (view/updateStatus/cancel/
 * review, previously three duplicated inline checks plus one bespoke buyer
 * check) and the AdminOrderController transition-validation fix (an admin
 * could previously jump an order straight from 'pending' to 'delivered' in
 * one call, silently triggering the COD-auto-paid derivation).
 */
class OrderAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function orderFor(User $buyer, User $farmer, array $overrides = []): Order
    {
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);

        return Order::factory()->create(array_merge([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
        ], $overrides));
    }

    // --- view() ---

    public function test_buyer_can_view_their_own_order(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $order = $this->orderFor($buyer, $farmer);
        Sanctum::actingAs($buyer);

        $this->getJson("/api/orders/{$order->id}")->assertStatus(200);
    }

    public function test_owning_farmer_can_view_the_order(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $order = $this->orderFor($buyer, $farmer);
        Sanctum::actingAs($farmer);

        $this->getJson("/api/orders/{$order->id}")->assertStatus(200);
    }

    public function test_unrelated_farmer_cannot_view_the_order(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $otherFarmer = User::factory()->create(['role' => 'farmer']);
        $order = $this->orderFor($buyer, $farmer);
        Sanctum::actingAs($otherFarmer);

        $this->getJson("/api/orders/{$order->id}")->assertStatus(403);
    }

    public function test_unrelated_buyer_cannot_view_the_order(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $otherBuyer = User::factory()->create(['role' => 'buyer']);
        $order = $this->orderFor($buyer, $farmer);
        Sanctum::actingAs($otherBuyer);

        $this->getJson("/api/orders/{$order->id}")->assertStatus(403);
    }

    public function test_admin_can_view_any_order(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderFor($buyer, $farmer);
        Sanctum::actingAs($admin);

        $this->getJson("/api/orders/{$order->id}")->assertStatus(200);
    }

    // --- updateStatus() ---

    public function test_buyer_cannot_update_order_status(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $order = $this->orderFor($buyer, $farmer, ['status' => 'pending']);
        Sanctum::actingAs($buyer);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(403);
    }

    public function test_unrelated_farmer_cannot_update_order_status(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $otherFarmer = User::factory()->create(['role' => 'farmer']);
        $order = $this->orderFor($buyer, $farmer, ['status' => 'pending']);
        Sanctum::actingAs($otherFarmer);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(403);
    }

    // --- cancel() ---

    public function test_unrelated_buyer_cannot_cancel_the_order(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $otherBuyer = User::factory()->create(['role' => 'buyer']);
        $order = $this->orderFor($buyer, $farmer, ['status' => 'pending']);
        Sanctum::actingAs($otherBuyer);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(403);
    }

    // --- review() ---

    public function test_farmer_cannot_review_the_order(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $order = $this->orderFor($buyer, $farmer, ['status' => 'delivered']);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/orders/{$order->id}/review", ['rating' => 5])->assertStatus(403);
    }

    public function test_admin_cannot_review_on_behalf_of_the_buyer(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderFor($buyer, $farmer, ['status' => 'delivered']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/orders/{$order->id}/review", ['rating' => 5])->assertStatus(403);
    }

    // --- AdminOrderController transition validation (the actual fix) ---

    public function test_admin_cannot_skip_order_status_transitions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = Order::factory()->create(['status' => 'pending', 'payment_method' => 'cod']);
        Sanctum::actingAs($admin);

        // Previously allowed — an admin could jump straight to 'delivered',
        // silently marking a COD order 'paid' without it ever passing
        // through confirmed/shipped.
        $response = $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'delivered']);

        $response->assertStatus(422);
        $fresh = $order->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('pending', $fresh->payment_status);
    }

    public function test_admin_can_still_make_valid_transitions_step_by_step(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = Order::factory()->create(['status' => 'pending']);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(200);
        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'shipped'])->assertStatus(200);
        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'delivered'])->assertStatus(200);

        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_admin_cannot_transition_out_of_a_delivered_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = Order::factory()->create(['status' => 'delivered']);
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'cancelled']);

        $response->assertStatus(422);
        $this->assertSame('delivered', $order->fresh()->status);
    }
}
