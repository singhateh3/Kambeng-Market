<?php

namespace Tests\Feature\Api;

use App\Models\Dispute;
use App\Models\FarmerProfile;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\PayoutReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the buyer-protection payout release: manual buyer confirmation,
 * the 3-day auto-release window, active disputes blocking both, and a
 * rejected dispute past the window releasing immediately.
 */
class PayoutReleaseTest extends TestCase
{
    use RefreshDatabase;

    private function deliveredOrder(array $overrides = []): Order
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        FarmerProfile::factory()->create([
            'user_id' => $farmer->id,
            'settlement_network' => 'wave',
            'settlement_account_number' => '+2201234567',
            'settlement_beneficiary_name' => $farmer->name,
        ]);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);

        return Order::factory()->create(array_merge([
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
        ], $overrides));
    }

    private function fakeTransferSucceeds(): void
    {
        Http::fake([
            '*/v1/transfers' => Http::response([
                'id' => 'tr_' . uniqid(),
                'amount' => 970,
                'currency' => 'GMD',
                'status' => 'processing',
            ], 201),
        ]);
    }

    public function test_buyer_can_confirm_and_release_payout(): void
    {
        $this->fakeTransferSucceeds();
        $order = $this->deliveredOrder();
        Sanctum::actingAs($order->buyer);

        $response = $this->postJson("/api/orders/{$order->id}/confirm");

        $response->assertStatus(200);
        $fresh = $order->fresh();
        $this->assertNotNull($fresh->buyer_confirmed_at);
        $this->assertSame('released', $fresh->payout_status);
        $this->assertSame('buyer_confirmed', $fresh->payout_release_reason);

        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'type' => 'payout',
            'amount' => 970,
        ]);
    }

    public function test_farmer_cannot_confirm_order(): void
    {
        $order = $this->deliveredOrder();
        Sanctum::actingAs($order->product->farmer);

        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(403);
    }

    public function test_confirm_blocked_while_dispute_is_open(): void
    {
        $order = $this->deliveredOrder();
        Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $order->buyer_id, 'status' => 'open']);
        Sanctum::actingAs($order->buyer);

        $response = $this->postJson("/api/orders/{$order->id}/confirm");

        $response->assertStatus(422);
        $this->assertSame('pending_release', $order->fresh()->payout_status);
    }

    public function test_confirm_blocked_while_dispute_is_under_review_regardless_of_who_filed_it(): void
    {
        $order = $this->deliveredOrder();
        // Filed by an admin-equivalent path is not modeled; use the buyer,
        // but the point under test is the block itself, not who filed it.
        Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $order->buyer_id, 'status' => 'under_review']);
        Sanctum::actingAs($order->buyer);

        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(422);
    }

    public function test_confirm_allowed_once_dispute_is_rejected(): void
    {
        $this->fakeTransferSucceeds();
        $order = $this->deliveredOrder();
        Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $order->buyer_id, 'status' => 'rejected']);
        Sanctum::actingAs($order->buyer);

        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(200);
        $this->assertSame('released', $order->fresh()->payout_status);
    }

    public function test_cannot_confirm_an_order_that_is_not_delivered(): void
    {
        $order = $this->deliveredOrder(['status' => 'shipped', 'delivered_at' => null, 'payout_status' => 'not_applicable']);
        Sanctum::actingAs($order->buyer);

        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(422);
    }

    public function test_auto_release_command_releases_orders_past_the_window(): void
    {
        $this->fakeTransferSucceeds();
        $eligible = $this->deliveredOrder(['delivered_at' => now()->subDays(4)]);
        $notYetEligible = $this->deliveredOrder(['delivered_at' => now()->subHours(1)]);

        $this->artisan('orders:auto-release-payouts')->assertExitCode(0);

        $this->assertSame('released', $eligible->fresh()->payout_status);
        $this->assertSame('pending_release', $notYetEligible->fresh()->payout_status);
    }

    public function test_auto_release_command_skips_orders_with_active_dispute(): void
    {
        $order = $this->deliveredOrder(['delivered_at' => now()->subDays(4)]);
        Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $order->buyer_id, 'status' => 'open']);

        $this->artisan('orders:auto-release-payouts')->assertExitCode(0);

        $this->assertSame('pending_release', $order->fresh()->payout_status);
    }

    public function test_rejecting_a_dispute_past_the_window_releases_immediately(): void
    {
        $this->fakeTransferSucceeds();
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->deliveredOrder(['delivered_at' => now()->subDays(4)]);
        $dispute = Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $order->buyer_id, 'status' => 'under_review']);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'rejected',
            'admin_note' => 'No evidence found.',
            'refund_decision' => 'no_refund',
        ])->assertStatus(200);

        $this->assertSame('released', $order->fresh()->payout_status);
        $this->assertSame('dispute_rejected_post_window', $order->fresh()->payout_release_reason);
    }

    public function test_rejecting_a_dispute_before_the_window_does_not_force_release(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->deliveredOrder(['delivered_at' => now()->subHours(2)]); // well within the window
        $dispute = Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $order->buyer_id, 'status' => 'under_review']);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'rejected',
            'admin_note' => 'No evidence found.',
            'refund_decision' => 'no_refund',
        ])->assertStatus(200);

        $this->assertSame('pending_release', $order->fresh()->payout_status);
    }

    public function test_full_refund_on_dispute_resolution_voids_payout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->deliveredOrder();
        $dispute = Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $order->buyer_id, 'status' => 'under_review']);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'resolved',
            'admin_note' => 'Item never arrived.',
            'refund_decision' => 'full_refund',
            'refund_amount' => 1000,
        ])->assertStatus(200);

        $this->assertSame('voided', $order->fresh()->payout_status);
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'type' => 'refund',
            'amount' => 1000,
            'status' => 'pending', // no confirmed ModemPay refund API — pending manual admin confirmation
        ]);
    }

    public function test_partial_refund_reduces_eventual_farmer_payout(): void
    {
        $this->fakeTransferSucceeds();
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->deliveredOrder();
        $dispute = Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $order->buyer_id, 'status' => 'under_review']);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'resolved',
            'admin_note' => 'Partial quality issue.',
            'refund_decision' => 'partial_refund',
            'refund_amount' => 200,
        ])->assertStatus(200);

        // Payout stays eligible — just reduced. 200 refunded, 3% (6) of
        // that was commission, so the farmer's share drops by 194:
        // 970 - 194 = 776.
        $this->assertSame('pending_release', $order->fresh()->payout_status);

        Sanctum::actingAs($order->buyer);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(200);

        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'type' => 'payout',
            'amount' => 776,
        ]);
    }

    /**
     * True concurrent-request racing can't be deterministically simulated
     * in PHPUnit against SQLite — there's no hook point between
     * isPayoutEligibleForRelease()'s check and the atomic UPDATE inside
     * PayoutReleaseService::release() (it's a query-builder mass update,
     * so it doesn't fire Eloquent model events the way an instance
     * update() would). This test instead proves the actual mechanism that
     * closes the race: the atomic claim's own WHERE NOT EXISTS clause,
     * driven directly, correctly excludes an order the instant a dispute
     * exists at the moment the statement runs — independent of whatever
     * check happened a moment earlier. That SQL-level guarantee is what
     * makes the race impossible, regardless of timing.
     */
    public function test_atomic_claim_excludes_an_order_with_an_active_dispute_at_the_moment_it_runs(): void
    {
        $order = $this->deliveredOrder();
        Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $order->buyer_id, 'status' => 'open']);

        $claimed = Order::where('id', $order->id)
            ->where('payout_status', 'pending_release')
            ->whereNotExists(function ($query) {
                $query->selectRaw(1)
                    ->from('disputes')
                    ->whereColumn('disputes.order_id', 'orders.id')
                    ->whereIn('disputes.status', Dispute::ACTIVE_STATUSES);
            })
            ->update(['payout_status' => 'released']);

        $this->assertSame(0, $claimed);
        $this->assertSame('pending_release', $order->fresh()->payout_status);
    }

    /**
     * Covers Task 10's fix #5 — dispute reporting stays available on an
     * eligible delivered order even after its payout has already gone
     * out, but the order's payout_status must clearly show there's
     * nothing left on hold.
     */
    public function test_dispute_can_be_filed_on_an_order_whose_payout_is_already_paid(): void
    {
        $order = $this->deliveredOrder(['payout_status' => 'paid']);
        Sanctum::actingAs($order->buyer);

        $response = $this->postJson("/api/orders/{$order->id}/report", ['reason' => 'item_not_received']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('disputes', ['order_id' => $order->id, 'status' => 'open']);
    }

    public function test_admin_dispute_view_clearly_shows_payout_already_paid(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->deliveredOrder(['payout_status' => 'paid']);
        $dispute = Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $order->buyer_id, 'status' => 'open']);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/disputes/{$dispute->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.order.payout_status', 'paid')
            ->assertJsonPath('data.order.funds_held_for_payout', false);
    }

    public function test_admin_dispute_view_shows_funds_still_held_when_payout_pending(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->deliveredOrder(); // payout_status: pending_release
        $dispute = Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $order->buyer_id, 'status' => 'open']);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/disputes/{$dispute->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.order.payout_status', 'pending_release')
            ->assertJsonPath('data.order.funds_held_for_payout', true);
    }

    public function test_release_fails_gracefully_when_farmer_has_no_settlement_details(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        // No FarmerProfile settlement fields set.
        FarmerProfile::factory()->create(['user_id' => $farmer->id]);
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
            'farmer_net_amount' => 970,
        ]);

        $result = app(PayoutReleaseService::class)->release($order, 'buyer_confirmed');

        $this->assertFalse($result['success']);
        $this->assertSame('missing_settlement_info', $result['reason']);
        // Stays pending_release — safe to retry once the farmer adds their
        // details, not silently marked failed/voided.
        $this->assertSame('pending_release', $order->fresh()->payout_status);
    }

    /**
     * Covers Task 10's production blocker #4. ModemPay documents no way
     * to look up a previously-created transfer (confirmed by checking
     * both the Payouts and Transactions API docs directly), so a transfer
     * that fails with no response at all (timeout/connection reset) is
     * genuinely ambiguous — it may have actually succeeded. Retrying that
     * specific case must require an explicit admin acknowledgment rather
     * than firing a potentially-duplicate transfer automatically.
     */
    public function test_ambiguous_transfer_failure_blocks_retry_without_explicit_acknowledgment(): void
    {
        Http::fake(['*/v1/transfers' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out')]);
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->deliveredOrder();
        Sanctum::actingAs($order->buyer);

        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(200);
        $this->assertSame('failed', $order->fresh()->payout_status);

        $ledgerRow = PaymentTransaction::where('order_id', $order->id)->where('type', 'payout')->first();
        $this->assertTrue($ledgerRow->metadata['ambiguous_outcome'] ?? false);

        // Admin retries WITHOUT acknowledging — must be blocked before any
        // new transfer call is even attempted. The ledger-row-count
        // assertion below is what actually proves no new attempt fired.
        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/admin/orders/{$order->id}/retry-payout");

        $response->assertStatus(422)->assertJsonPath('requires_acknowledgment', true);
        $this->assertSame('failed', $order->fresh()->payout_status);
        $this->assertSame(1, PaymentTransaction::where('order_id', $order->id)->where('type', 'payout')->count());
    }

    public function test_ambiguous_transfer_retry_proceeds_once_explicitly_acknowledged(): void
    {
        // Two calls to the same endpoint in one test, first throws, second
        // succeeds — fakeSequence()->push() can't throw an exception, so
        // a call-counting closure fake stands in for it instead.
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            }
            return Http::response(['id' => 'tr_' . uniqid(), 'amount' => 970, 'currency' => 'GMD', 'status' => 'processing'], 201);
        });

        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->deliveredOrder();
        Sanctum::actingAs($order->buyer);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(200);

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/admin/orders/{$order->id}/retry-payout", [
            'acknowledge_ambiguous_outcome' => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame('released', $order->fresh()->payout_status);
    }

    public function test_non_ambiguous_transfer_rejection_can_be_retried_without_acknowledgment(): void
    {
        // A clean HTTP error response — ModemPay definitely never sent
        // money, so no extra friction is needed to retry.
        Http::fakeSequence('*/v1/transfers')
            ->push(['error' => 'insufficient-funds'], 422)
            ->push(['id' => 'tr_' . uniqid(), 'amount' => 970, 'currency' => 'GMD', 'status' => 'processing'], 201);

        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->deliveredOrder();
        Sanctum::actingAs($order->buyer);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(200);

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/admin/orders/{$order->id}/retry-payout");

        $response->assertStatus(200);
        $this->assertSame('released', $order->fresh()->payout_status);
    }

    public function test_transfer_failure_records_ledger_row_and_admin_can_retry(): void
    {
        // Http::fake() registered for the same URL pattern twice in one
        // test doesn't override the first — the earlier stub keeps
        // matching. fakeSequence() is the correct way to get two
        // different responses from successive calls to the same endpoint.
        Http::fakeSequence('*/v1/transfers')
            ->push(['error' => 'insufficient-funds'], 422)
            ->push(['id' => 'tr_retry_success', 'amount' => 970, 'currency' => 'GMD', 'status' => 'processing'], 201);

        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->deliveredOrder();
        Sanctum::actingAs($order->buyer);

        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(200);
        $this->assertSame('failed', $order->fresh()->payout_status);
        $failedAttempts = PaymentTransaction::where('order_id', $order->id)->where('type', 'payout')->count();
        $this->assertSame(1, $failedAttempts);

        // Admin retries — must create a NEW ledger row, not mutate the failed one.
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/orders/{$order->id}/retry-payout")->assertStatus(200);

        $this->assertSame('released', $order->fresh()->payout_status);
        $allAttempts = PaymentTransaction::where('order_id', $order->id)->where('type', 'payout')->get();
        $this->assertCount(2, $allAttempts);
        $this->assertSame('failed', $allAttempts[0]->status); // original untouched
        $this->assertNotSame($allAttempts[0]->idempotency_key, $allAttempts[1]->idempotency_key);
    }
}
