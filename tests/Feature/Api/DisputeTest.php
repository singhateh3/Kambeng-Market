<?php

namespace Tests\Feature\Api;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DisputeTest extends TestCase
{
    use RefreshDatabase;

    private function reportableOrder(array $overrides = []): Order
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);

        return Order::factory()->create(array_merge([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'delivered',
        ], $overrides));
    }

    public function test_buyer_can_report_their_own_eligible_order(): void
    {
        $order = $this->reportableOrder();
        Sanctum::actingAs($order->buyer);

        $response = $this->postJson("/api/orders/{$order->id}/report", [
            'reason' => 'item_not_received',
            'description' => 'Never showed up.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.reason', 'item_not_received')
            ->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('disputes', [
            'order_id' => $order->id,
            'reported_by' => $order->buyer_id,
            'reason' => 'item_not_received',
            'status' => 'open',
        ]);
    }

    public function test_buyer_cannot_report_another_buyers_order(): void
    {
        $order = $this->reportableOrder();
        $otherBuyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($otherBuyer);

        $response = $this->postJson("/api/orders/{$order->id}/report", [
            'reason' => 'item_not_received',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('disputes', 0);
    }

    public function test_unauthenticated_user_cannot_report(): void
    {
        $order = $this->reportableOrder();

        $response = $this->postJson("/api/orders/{$order->id}/report", [
            'reason' => 'item_not_received',
        ]);

        $response->assertStatus(401);
    }

    public function test_farmer_cannot_impersonate_buyer_via_ownership_field(): void
    {
        $order = $this->reportableOrder();
        Sanctum::actingAs($order->product->farmer);

        // Even if the farmer sends the buyer's own ID in the payload, the
        // server never trusts anything but auth()->id() for ownership, and
        // the farmer isn't the order's buyer regardless.
        $response = $this->postJson("/api/orders/{$order->id}/report", [
            'reason' => 'item_not_received',
            'buyer_id' => $order->buyer_id,
            'reported_by' => $order->buyer_id,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('disputes', 0);
    }

    public function test_invalid_reason_is_rejected(): void
    {
        $order = $this->reportableOrder();
        Sanctum::actingAs($order->buyer);

        $response = $this->postJson("/api/orders/{$order->id}/report", [
            'reason' => 'not_a_real_reason',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_missing_reason_is_rejected(): void
    {
        $order = $this->reportableOrder();
        Sanctum::actingAs($order->buyer);

        $response = $this->postJson("/api/orders/{$order->id}/report", []);

        $response->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_description_over_max_length_is_rejected(): void
    {
        $order = $this->reportableOrder();
        Sanctum::actingAs($order->buyer);

        $response = $this->postJson("/api/orders/{$order->id}/report", [
            'reason' => 'other',
            'description' => str_repeat('a', 1001),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('description');
    }

    public function test_pending_order_cannot_be_reported(): void
    {
        $order = $this->reportableOrder(['status' => 'pending']);
        Sanctum::actingAs($order->buyer);

        $response = $this->postJson("/api/orders/{$order->id}/report", [
            'reason' => 'item_not_received',
        ]);

        $response->assertStatus(422);
    }

    public function test_cancelled_order_cannot_be_reported(): void
    {
        $order = $this->reportableOrder(['status' => 'cancelled']);
        Sanctum::actingAs($order->buyer);

        $response = $this->postJson("/api/orders/{$order->id}/report", [
            'reason' => 'item_not_received',
        ]);

        $response->assertStatus(422);
    }

    public function test_confirmed_and_shipped_orders_can_be_reported(): void
    {
        foreach (['confirmed', 'shipped'] as $status) {
            $order = $this->reportableOrder(['status' => $status]);
            Sanctum::actingAs($order->buyer);

            $response = $this->postJson("/api/orders/{$order->id}/report", [
                'reason' => 'farmer_unresponsive',
            ]);

            $response->assertStatus(201);
        }
    }

    public function test_duplicate_active_dispute_on_same_order_is_rejected(): void
    {
        $order = $this->reportableOrder();
        Sanctum::actingAs($order->buyer);

        $this->postJson("/api/orders/{$order->id}/report", ['reason' => 'item_not_received'])
            ->assertStatus(201);

        $response = $this->postJson("/api/orders/{$order->id}/report", ['reason' => 'quality_issue']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('disputes', 1);
    }

    public function test_new_dispute_allowed_after_prior_one_is_resolved(): void
    {
        $order = $this->reportableOrder();
        Dispute::factory()->create([
            'order_id' => $order->id,
            'reported_by' => $order->buyer_id,
            'status' => 'resolved',
        ]);

        Sanctum::actingAs($order->buyer);

        $response = $this->postJson("/api/orders/{$order->id}/report", ['reason' => 'quality_issue']);

        $response->assertStatus(201);
        $this->assertDatabaseCount('disputes', 2);
    }

    public function test_dispute_opened_notifies_farmer_and_admins(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->reportableOrder();
        Sanctum::actingAs($order->buyer);

        $this->postJson("/api/orders/{$order->id}/report", ['reason' => 'item_not_received'])
            ->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $order->product->farmer_id,
            'type' => 'dispute_opened',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'dispute_opened',
        ]);
    }
}
