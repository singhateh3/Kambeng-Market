<?php

// tests/Feature/Api/OrderSecretExposureTest.php
//
// Phase 3A P1 audit fix: OrderController's index()/show() (and
// updateStatus()/cancel()/confirm(), which have the identical issue)
// return the Order model directly rather than through OrderResource.
// orders.modempay_intent_secret is the token ModemPay's own
// GET /v1/payments/verify requires to check a payment intent — a real
// credential, not a display id — and had no protection at all.
//
// OrderResource was inspected as the "preferred minimal solution" but
// doesn't preserve the existing contract: OrderDetailsPage.jsx reads
// order.product_id, order.buyer_id, and order.dispute directly, none of
// which OrderResource currently exposes (it only nests product/buyer as
// full sub-resources and has no dispute field at all). Switching to it
// would have been the "unnecessarily change the response shape" case the
// audit explicitly said to avoid — so the fix is Order::$hidden instead,
// which protects every raw-serialization call site (index, show,
// updateStatus, cancel, confirm) with one line and changes nothing else
// in the response shape.

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderSecretExposureTest extends TestCase
{
    use RefreshDatabase;

    private function orderWithSecret(User $buyer, User $farmer, array $overrides = []): Order
    {
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);

        return Order::factory()->create(array_merge([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            // A realistic-shaped but obviously-fake value — never a real
            // secret, per this task's own security requirements.
            'modempay_intent_id' => 'pi_test_00000000',
            'modempay_intent_secret' => 'test_only_fake_secret_do_not_use',
        ], $overrides));
    }

    // --- the actual fix ---

    public function test_modempay_intent_secret_is_absent_from_order_show_response(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $order = $this->orderWithSecret($buyer, $farmer);
        Sanctum::actingAs($buyer);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonMissingPath('data.modempay_intent_secret');
        $this->assertStringNotContainsString('test_only_fake_secret_do_not_use', $response->getContent());
    }

    public function test_modempay_intent_secret_is_absent_from_order_index_response(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $this->orderWithSecret($buyer, $farmer);
        Sanctum::actingAs($buyer);

        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonMissingPath('data.0.modempay_intent_secret');
        $this->assertStringNotContainsString('test_only_fake_secret_do_not_use', $response->getContent());
    }

    public function test_modempay_intent_secret_is_absent_after_a_status_update(): void
    {
        // updateStatus()/cancel()/confirm() all return the raw Order the
        // same way show()/index() do — Order::$hidden protects all of
        // them at once, not just the two the audit named.
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $order = $this->orderWithSecret($buyer, $farmer, ['status' => 'pending']);
        Sanctum::actingAs($farmer);

        $response = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed']);

        $response->assertStatus(200);
        $response->assertJsonMissingPath('data.modempay_intent_secret');
        $this->assertStringNotContainsString('test_only_fake_secret_do_not_use', $response->getContent());
    }

    public function test_modempay_intent_secret_is_absent_after_cancel(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $order = $this->orderWithSecret($buyer, $farmer, ['status' => 'pending']);
        Sanctum::actingAs($buyer);

        $response = $this->postJson("/api/orders/{$order->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonMissingPath('data.modempay_intent_secret');
        $this->assertStringNotContainsString('test_only_fake_secret_do_not_use', $response->getContent());
    }

    // --- distinguishing secrets from ordinary correlation ids ---

    public function test_modempay_intent_id_is_not_hidden_since_it_is_not_a_secret(): void
    {
        // Only intent_secret is a credential; intent_id is a stable
        // correlation id (per the migration that added intent_secret) —
        // confirms the fix didn't over-hide.
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $order = $this->orderWithSecret($buyer, $farmer);
        Sanctum::actingAs($buyer);

        $this->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('data.modempay_intent_id', 'pi_test_00000000');
    }

    // --- authorization + existing behavior preserved ---

    public function test_buyer_can_still_retrieve_their_order_with_expected_fields(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $order = $this->orderWithSecret($buyer, $farmer);
        Sanctum::actingAs($buyer);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $order->id)
            // OrderDetailsPage.jsx reads these as top-level scalars —
            // confirming the response shape is otherwise unchanged.
            ->assertJsonPath('data.buyer_id', $buyer->id)
            ->assertJsonPath('data.product_id', $order->product_id)
            ->assertJsonStructure(['data' => ['id', 'status', 'total_price', 'buyer', 'product' => ['farmer']]]);
    }

    public function test_farmer_can_still_retrieve_orders_for_their_products(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $this->orderWithSecret($buyer, $farmer);
        Sanctum::actingAs($farmer);

        $this->getJson('/api/orders')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_still_retrieve_any_order(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderWithSecret($buyer, $farmer);
        Sanctum::actingAs($admin);

        $this->getJson("/api/orders/{$order->id}")->assertStatus(200)->assertJsonPath('data.id', $order->id);
    }

    public function test_unrelated_buyer_still_cannot_view_the_order(): void
    {
        // Authorization is untouched by this fix — same OrderPolicy check.
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $otherBuyer = User::factory()->create(['role' => 'buyer']);
        $order = $this->orderWithSecret($buyer, $farmer);
        Sanctum::actingAs($otherBuyer);

        $this->getJson("/api/orders/{$order->id}")->assertStatus(403);
    }
}
