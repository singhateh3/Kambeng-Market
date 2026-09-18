<?php

// tests/Feature/Api/AdminOrderDeletionSafetyTest.php
//
// Phase 3B — payment_transactions.order_id, reviews.order_id, and
// disputes.order_id are now RESTRICT instead of CASCADE (see
// 2026_09_17_000001_restrict_business_history_cascades_on_user_deletion),
// so AdminOrderController::destroy() can no longer silently destroy a
// financial/business record by deleting its parent order. The controller
// pre-checks the same condition to return a clear 422 instead of a raw
// database-constraint error; the database restriction is the actual
// enforcement, not this check — proven by the fact this test suite runs
// against real foreign-key constraints (RefreshDatabase applies every
// migration, including the FK change, to a real SQLite database), not a
// mock.

namespace Tests\Feature\Api;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrderDeletionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_with_a_payment_transaction_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = Order::factory()->create();
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => 'charge',
            'amount' => 100,
            'currency' => 'GMD',
            'status' => 'succeeded',
            'idempotency_key' => 'idem-safety-test',
        ]);
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/admin/orders/{$order->id}");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('payment_transactions', ['order_id' => $order->id]);
    }

    public function test_order_with_a_review_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = Order::factory()->create();
        Review::create(['order_id' => $order->id, 'user_id' => $order->buyer_id, 'rating' => 5]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/orders/{$order->id}")->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_order_with_a_dispute_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = Order::factory()->create();
        Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $order->buyer_id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/orders/{$order->id}")->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_order_with_no_financial_or_business_history_can_still_be_deleted(): void
    {
        // Confirms the safeguard is scoped to orders that actually have
        // history — not a blanket ban on ever deleting an order.
        $admin = User::factory()->create(['role' => 'admin']);
        $order = Order::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/admin/orders/{$order->id}");

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }
}
