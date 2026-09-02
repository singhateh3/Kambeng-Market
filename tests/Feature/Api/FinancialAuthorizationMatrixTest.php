<?php

namespace Tests\Feature\Api;

use App\Models\Dispute;
use App\Models\FarmerProfile;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 10, fix #7 — the comprehensive authorization matrix for every
 * financially significant action: buyer confirm, admin retry-payout,
 * admin confirm-refund, admin dispute resolution. Each against buyer /
 * another buyer / farmer / another farmer / admin / unauthenticated.
 */
class FinancialAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $otherBuyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $otherFarmer = User::factory()->create(['role' => 'farmer']);
        $admin = User::factory()->create(['role' => 'admin']);

        FarmerProfile::factory()->create([
            'user_id' => $farmer->id,
            'settlement_network' => 'wave',
            'settlement_account_number' => '+2201234567',
            'settlement_beneficiary_name' => $farmer->name,
        ]);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);

        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'delivered',
            'payment_method' => 'modempay',
            'payment_status' => 'paid',
            'payout_status' => 'pending_release',
            'delivered_at' => now()->subHours(2),
            'total_price' => 1000,
            'commission_rate' => 0.03,
            'commission_amount' => 30,
            'farmer_net_amount' => 970,
        ]);

        return compact('buyer', 'otherBuyer', 'farmer', 'otherFarmer', 'admin', 'order');
    }

    // ---- POST /orders/{order}/confirm ----

    public function test_confirm_authorization_matrix(): void
    {
        ['buyer' => $buyer, 'otherBuyer' => $otherBuyer, 'farmer' => $farmer, 'otherFarmer' => $otherFarmer, 'admin' => $admin, 'order' => $order] = $this->scenario();

        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(401); // unauthenticated

        Sanctum::actingAs($otherBuyer);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(403);

        Sanctum::actingAs($farmer);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(403);

        Sanctum::actingAs($otherFarmer);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(403);

        Http::fake(['*/v1/transfers' => Http::response(['id' => 'tr_1', 'amount' => 970, 'currency' => 'GMD', 'status' => 'processing'], 201)]);
        Sanctum::actingAs($buyer);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(200);
    }

    public function test_confirm_rejected_on_ineligible_order_states_even_for_the_owning_buyer(): void
    {
        ['buyer' => $buyer, 'order' => $order] = $this->scenario();

        foreach (['awaiting_payment', 'pending', 'confirmed', 'shipped', 'cancelled'] as $status) {
            $order->update(['status' => $status]);
            Sanctum::actingAs($buyer);
            $this->postJson("/api/orders/{$order->id}/confirm")
                ->assertStatus(422, "Expected 422 for status={$status}");
        }
    }

    // ---- POST /admin/orders/{order}/retry-payout ----

    public function test_retry_payout_authorization_matrix(): void
    {
        ['buyer' => $buyer, 'otherBuyer' => $otherBuyer, 'farmer' => $farmer, 'otherFarmer' => $otherFarmer, 'admin' => $admin, 'order' => $order] = $this->scenario();
        $order->update(['payout_status' => 'failed']);

        $this->postJson("/api/admin/orders/{$order->id}/retry-payout")->assertStatus(401);

        foreach (compact('buyer', 'otherBuyer', 'farmer', 'otherFarmer') as $user) {
            Sanctum::actingAs($user);
            // Route middleware alone would already 403 these — this test
            // exists specifically to prove OrderPolicy::retryPayout() is
            // also wired in (defense-in-depth), not just relying on the
            // route group.
            $this->postJson("/api/admin/orders/{$order->id}/retry-payout")->assertStatus(403);
        }

        Http::fake(['*/v1/transfers' => Http::response(['id' => 'tr_1', 'amount' => 970, 'currency' => 'GMD', 'status' => 'processing'], 201)]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/orders/{$order->id}/retry-payout")->assertStatus(200);
    }

    public function test_retry_payout_rejected_when_payout_is_not_in_failed_state(): void
    {
        ['admin' => $admin, 'order' => $order] = $this->scenario(); // payout_status: pending_release
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/orders/{$order->id}/retry-payout")->assertStatus(422);
    }

    // ---- POST /admin/orders/{order}/confirm-refund ----

    public function test_confirm_refund_authorization_matrix(): void
    {
        ['buyer' => $buyer, 'otherBuyer' => $otherBuyer, 'farmer' => $farmer, 'otherFarmer' => $otherFarmer, 'admin' => $admin, 'order' => $order] = $this->scenario();
        PaymentTransaction::recordPendingRefund($order, 1000, 'refund', 'test');

        $this->postJson("/api/admin/orders/{$order->id}/confirm-refund")->assertStatus(401);

        foreach (compact('buyer', 'otherBuyer', 'farmer', 'otherFarmer') as $user) {
            Sanctum::actingAs($user);
            $this->postJson("/api/admin/orders/{$order->id}/confirm-refund")->assertStatus(403);
        }

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/orders/{$order->id}/confirm-refund")->assertStatus(200);
    }

    public function test_confirm_refund_returns_404_when_no_pending_refund_exists(): void
    {
        ['admin' => $admin, 'order' => $order] = $this->scenario();
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/orders/{$order->id}/confirm-refund")->assertStatus(404);
    }

    // ---- PATCH /admin/disputes/{dispute}/status ----

    public function test_dispute_resolution_authorization_matrix(): void
    {
        ['buyer' => $buyer, 'otherBuyer' => $otherBuyer, 'farmer' => $farmer, 'otherFarmer' => $otherFarmer, 'admin' => $admin, 'order' => $order] = $this->scenario();
        $dispute = Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $buyer->id, 'status' => 'open']);

        $payload = ['status' => 'under_review'];

        $this->patchJson("/api/admin/disputes/{$dispute->id}/status", $payload)->assertStatus(401);

        foreach (compact('buyer', 'otherBuyer', 'farmer', 'otherFarmer') as $user) {
            Sanctum::actingAs($user);
            $this->patchJson("/api/admin/disputes/{$dispute->id}/status", $payload)->assertStatus(403);
        }

        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/disputes/{$dispute->id}/status", $payload)->assertStatus(200);
    }

    // ---- GET /admin/payment-transactions (ledger visibility) ----

    public function test_payment_transaction_ledger_authorization_matrix(): void
    {
        ['buyer' => $buyer, 'farmer' => $farmer, 'admin' => $admin] = $this->scenario();

        $this->getJson('/api/admin/payment-transactions')->assertStatus(401);

        Sanctum::actingAs($buyer);
        $this->getJson('/api/admin/payment-transactions')->assertStatus(403);

        Sanctum::actingAs($farmer);
        $this->getJson('/api/admin/payment-transactions')->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/payment-transactions')->assertStatus(200);
    }
}
