<?php

// tests/Feature/Api/UserDeactivationTest.php
//
// Phase 3B — DELETE /api/admin/users/{user} no longer hard-deletes; it
// deactivates + anonymizes via UserDeactivationService. Endpoint path,
// method, and response shape are unchanged (see AdminUserController::
// destroy()); only the actual effect and message text changed. Covers the
// approved decisions: outstanding obligations never block deactivation,
// Google/Apple provider IDs are retained, and business/financial history
// (orders, payment_transactions, disputes, reviews) survives untouched.

namespace Tests\Feature\Api;

use App\Models\Dispute;
use App\Models\FarmerProfile;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserDeactivationTest extends TestCase
{
    use RefreshDatabase;

    // --- authorization ---

    public function test_non_admin_cannot_deactivate_a_user(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $target = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $this->deleteJson("/api/admin/users/{$target->id}")->assertStatus(403);
    }

    public function test_unauthenticated_request_cannot_deactivate_a_user(): void
    {
        $target = User::factory()->create(['role' => 'buyer']);

        $this->deleteJson("/api/admin/users/{$target->id}")->assertStatus(401);
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/admin/users/{$admin->id}");

        $response->assertStatus(422)->assertJsonPath('message', 'You cannot deactivate your own account');
        $this->assertNull($admin->fresh()->deactivated_at);
    }

    public function test_admin_can_deactivate_another_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/admin/users/{$target->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User deactivated successfully');
        $this->assertNotNull($target->fresh()->deactivated_at);
    }

    public function test_deactivating_an_already_deactivated_user_is_rejected_not_reprocessed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'buyer', 'deactivated_at' => now()]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$target->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This account is already deactivated');
    }

    // --- anonymization ---

    public function test_deactivation_anonymizes_personal_information(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create([
            'role' => 'buyer',
            'name' => 'Real Name',
            'email' => 'real-person@example.com',
            'phone' => '+2207000001',
            'location' => 'Banjul',
            'avatar' => 'https://cloudinary.example/real-avatar.jpg',
            'avatar_public_id' => 'avatars/real-avatar',
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$target->id}")->assertStatus(200);

        $fresh = $target->fresh();
        $this->assertSame('Deactivated User', $fresh->name);
        $this->assertSame("deleted-user-{$target->id}@deleted.kambeng.invalid", $fresh->email);
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->location);
        $this->assertNull($fresh->avatar);
        $this->assertNull($fresh->avatar_public_id);
        $this->assertNull($fresh->password);
        $this->assertStringNotContainsString('Real Name', $fresh->name);
        $this->assertStringNotContainsString('real-person', $fresh->email);
    }

    public function test_anonymized_email_is_unique_across_multiple_deactivations(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetA = User::factory()->create(['role' => 'buyer']);
        $targetB = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$targetA->id}")->assertStatus(200);
        $this->deleteJson("/api/admin/users/{$targetB->id}")->assertStatus(200);

        $this->assertNotSame($targetA->fresh()->email, $targetB->fresh()->email);
        $this->assertSame(2, User::whereIn('id', [$targetA->id, $targetB->id])->count());
    }

    public function test_provider_id_is_retained_through_deactivation(): void
    {
        // The approved decision: retaining provider/provider_id lets a
        // returning Google/Apple identity resolve to this same row (and
        // then be rejected by the active-account gate) instead of
        // silently creating a disconnected duplicate account.
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create([
            'role' => 'buyer',
            'provider' => 'google',
            'provider_id' => 'google-sub-keep-me',
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$target->id}")->assertStatus(200);

        $fresh = $target->fresh();
        $this->assertSame('google', $fresh->provider);
        $this->assertSame('google-sub-keep-me', $fresh->provider_id);
    }

    public function test_farmer_profile_identity_and_documents_are_anonymized_but_verification_facts_remain(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $profile = FarmerProfile::factory()->create([
            'user_id' => $farmer->id,
            'farm_name' => 'Real Farm Name',
            'farm_location' => 'Real Farm Location',
            'bio' => 'A real farmer bio.',
            'id_verified' => true,
            'verification_document' => 'documents/real-id.pdf',
            'business_license' => 'documents/real-license.pdf',
            'id_document' => 'documents/real-id-card.pdf',
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$farmer->id}")->assertStatus(200);

        $freshProfile = $profile->fresh();
        // Anonymized identity.
        $this->assertSame('Deactivated Farmer', $freshProfile->farm_name);
        $this->assertSame('Not disclosed', $freshProfile->farm_location);
        $this->assertNull($freshProfile->bio);
        // Sensitive documents removed.
        $this->assertNull($freshProfile->verification_document);
        $this->assertNull($freshProfile->business_license);
        $this->assertNull($freshProfile->id_document);
        // Historical verification FACT retained — was this farmer ever
        // verified, regardless of the documents that proved it.
        $this->assertTrue((bool) $freshProfile->id_verified);
    }

    public function test_settlement_details_are_cleared_when_no_payout_is_pending(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $profile = FarmerProfile::factory()->create([
            'user_id' => $farmer->id,
            'settlement_network' => 'wave',
            'settlement_account_number' => '7000000',
            'settlement_beneficiary_name' => 'Real Farmer',
            'settlement_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/admin/users/{$farmer->id}");

        $response->assertStatus(200)->assertJsonPath('message', 'User deactivated successfully');
        $fresh = $profile->fresh();
        $this->assertNull($fresh->settlement_network);
        $this->assertNull($fresh->settlement_account_number);
        $this->assertNull($fresh->settlement_beneficiary_name);
        $this->assertNull($fresh->settlement_verified_at);
    }

    public function test_settlement_details_are_retained_while_a_payout_is_pending_release(): void
    {
        // The one deliberate exception to "never block on outstanding
        // obligations": PayoutReleaseService reads settlement details
        // FRESH at release time, so anonymizing them out from under an
        // in-flight payout would turn a real payout into a
        // missing_settlement_info failure — data corruption caused by this
        // feature's own anonymization step.
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $profile = FarmerProfile::factory()->create([
            'user_id' => $farmer->id,
            'settlement_network' => 'wave',
            'settlement_account_number' => '7000000',
            'settlement_beneficiary_name' => 'Real Farmer',
        ]);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        Order::factory()->create(['product_id' => $product->id, 'payout_status' => 'pending_release']);
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/admin/users/{$farmer->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'User deactivated successfully. Settlement details were retained because a payout for this farmer is still pending release.');
        $fresh = $profile->fresh();
        $this->assertSame('wave', $fresh->settlement_network);
        $this->assertSame('7000000', $fresh->settlement_account_number);
        $this->assertSame('Real Farmer', $fresh->settlement_beneficiary_name);
        // Everything else about the account is still anonymized immediately.
        $this->assertSame('Deactivated User', $farmer->fresh()->name);
    }

    public function test_settlement_details_are_retained_while_a_payout_is_released_awaiting_confirmation(): void
    {
        // 'released' means PayoutReleaseService::release() has already
        // dispatched the transfer to ModemPay but the confirmation webhook
        // hasn't landed yet — a subsequent failure report can still move
        // this to 'failed' and later back to 'pending_release' via
        // AdminOrderController::retryPayout(), which reads these same
        // fields again at that point.
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $profile = FarmerProfile::factory()->create([
            'user_id' => $farmer->id,
            'settlement_network' => 'wave',
            'settlement_account_number' => '7000000',
            'settlement_beneficiary_name' => 'Real Farmer',
        ]);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        Order::factory()->create(['product_id' => $product->id, 'payout_status' => 'released']);
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/admin/users/{$farmer->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'User deactivated successfully. Settlement details were retained because a payout for this farmer is still pending release.');
        $fresh = $profile->fresh();
        $this->assertSame('wave', $fresh->settlement_network);
        $this->assertSame('7000000', $fresh->settlement_account_number);
        $this->assertSame('Real Farmer', $fresh->settlement_beneficiary_name);
    }

    public function test_settlement_details_are_retained_while_a_payout_has_failed_and_may_be_retried(): void
    {
        // 'failed' can be reset back to 'pending_release' by
        // AdminOrderController::retryPayout(), which then calls
        // PayoutReleaseService::release() again — re-reading these same
        // settlement fields at that later moment.
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $profile = FarmerProfile::factory()->create([
            'user_id' => $farmer->id,
            'settlement_network' => 'wave',
            'settlement_account_number' => '7000000',
            'settlement_beneficiary_name' => 'Real Farmer',
        ]);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        Order::factory()->create(['product_id' => $product->id, 'payout_status' => 'failed']);
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/admin/users/{$farmer->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'User deactivated successfully. Settlement details were retained because a payout for this farmer is still pending release.');
        $fresh = $profile->fresh();
        $this->assertSame('wave', $fresh->settlement_network);
        $this->assertSame('7000000', $fresh->settlement_account_number);
        $this->assertSame('Real Farmer', $fresh->settlement_beneficiary_name);
    }

    public function test_settlement_details_are_cleared_when_every_payout_is_in_a_terminal_state(): void
    {
        // 'paid' (confirmed success), 'voided', and 'not_applicable' are
        // all terminal — nothing ever calls release() again for an order
        // in one of these states, so there is no future read to protect
        // and settlement details should still be anonymized normally.
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $profile = FarmerProfile::factory()->create([
            'user_id' => $farmer->id,
            'settlement_network' => 'wave',
            'settlement_account_number' => '7000000',
            'settlement_beneficiary_name' => 'Real Farmer',
        ]);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        Order::factory()->create(['product_id' => $product->id, 'payout_status' => 'paid']);
        Order::factory()->create(['product_id' => $product->id, 'payout_status' => 'voided']);
        Order::factory()->create(['product_id' => $product->id, 'payout_status' => 'not_applicable']);
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/admin/users/{$farmer->id}");

        $response->assertStatus(200)->assertJsonPath('message', 'User deactivated successfully');
        $fresh = $profile->fresh();
        $this->assertNull($fresh->settlement_network);
        $this->assertNull($fresh->settlement_account_number);
        $this->assertNull($fresh->settlement_beneficiary_name);
    }

    public function test_a_retried_payout_succeeds_after_deactivation_because_settlement_survived(): void
    {
        // The actual rationale end-to-end: failed -> deactivate ->
        // retryPayout() -> pending_release -> release() — proving
        // deactivation did not remove the settlement information needed
        // for this real retry to succeed. Reuses the exact ModemPay-fake
        // pattern already established in PayoutReleaseTest.php.
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        FarmerProfile::factory()->create([
            'user_id' => $farmer->id,
            'settlement_network' => 'wave',
            'settlement_account_number' => '7000000',
            'settlement_beneficiary_name' => 'Real Farmer',
        ]);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        $order = Order::factory()->create([
            'product_id' => $product->id,
            'status' => 'delivered',
            'payment_method' => 'modempay',
            'payment_status' => 'paid',
            'payout_status' => 'failed',
            'total_price' => 1000,
            'commission_rate' => 0.03,
            'commission_amount' => 30,
            'farmer_net_amount' => 970,
        ]);
        // A real 'failed' order always has the payout attempt that failed
        // — retryPayout() reads it to check for an ambiguous outcome.
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => 'payout',
            'amount' => 970,
            'currency' => 'GMD',
            'status' => 'failed',
            'idempotency_key' => 'idem-first-attempt',
            'metadata' => ['ambiguous_outcome' => false],
        ]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/admin/users/{$farmer->id}")->assertStatus(200);

        Http::fake([
            '*/v1/transfers' => Http::response([
                'id' => 'tr_' . uniqid(),
                'amount' => 970,
                'currency' => 'GMD',
                'status' => 'processing',
            ], 201),
        ]);

        $response = $this->postJson("/api/admin/orders/{$order->id}/retry-payout");

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertSame('released', $order->fresh()->payout_status);
    }

    // --- tokens ---

    public function test_deactivation_revokes_all_sanctum_tokens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'buyer']);
        $target->createToken('device-a');
        $target->createToken('device-b');
        $this->assertSame(2, $target->tokens()->count());

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/admin/users/{$target->id}")->assertStatus(200);

        $this->assertSame(0, $target->tokens()->count());
    }

    // --- outstanding obligations never block deactivation ---

    public function test_deactivation_is_not_blocked_by_an_awaiting_payment_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'awaiting_payment']);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$buyer->id}")->assertStatus(200);
        $this->assertNotNull($buyer->fresh()->deactivated_at);
    }

    public function test_deactivation_is_not_blocked_by_an_active_dispute(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $buyer->id, 'status' => 'open']);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$buyer->id}")->assertStatus(200);
        $this->assertNotNull($buyer->fresh()->deactivated_at);
    }

    public function test_deactivation_is_not_blocked_by_pending_farmer_verification(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer', 'verification_status' => 'pending']);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$farmer->id}")->assertStatus(200);
        $this->assertNotNull($farmer->fresh()->deactivated_at);
    }

    // --- financial/business history preservation ---

    public function test_orders_payment_transactions_disputes_and_reviews_survive_deactivation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'delivered',
            'commission_amount' => 12.50,
            'farmer_net_amount' => 87.50,
            'modempay_intent_id' => 'pi_keep_me',
        ]);
        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => 'charge',
            'amount' => 100,
            'currency' => 'GMD',
            'commission_amount' => 12.50,
            'status' => 'succeeded',
            'modempay_reference' => 'pi_keep_me',
            'idempotency_key' => 'idem-keep-me',
        ]);
        $review = Review::create(['order_id' => $order->id, 'user_id' => $buyer->id, 'rating' => 5, 'comment' => 'Great produce.']);
        $dispute = Dispute::factory()->create(['order_id' => $order->id, 'reported_by' => $buyer->id, 'status' => 'resolved']);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/admin/users/{$buyer->id}")->assertStatus(200);
        $this->deleteJson("/api/admin/users/{$farmer->id}")->assertStatus(200);

        // Every historical row still exists, byte-for-byte, after BOTH
        // parties to the order are deactivated.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'commission_amount' => 12.50,
            'farmer_net_amount' => 87.50,
            'modempay_intent_id' => 'pi_keep_me',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'amount' => 100,
            'status' => 'succeeded',
            'modempay_reference' => 'pi_keep_me',
        ]);
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'rating' => 5, 'comment' => 'Great produce.']);
        $this->assertDatabaseHas('disputes', ['id' => $dispute->id, 'status' => 'resolved']);

        // The order/dispute/review/transaction rows still resolve their
        // (now-anonymized) buyer/farmer relationships without error.
        $this->assertNotNull($order->fresh()->buyer);
        $this->assertNotNull($order->fresh()->product->farmer);
    }

    // --- farmer listings ---

    public function test_farmer_deactivation_delists_active_products_without_deleting_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $activeProduct = Product::factory()->create(['farmer_id' => $farmer->id, 'status' => 'active']);
        $soldProduct = Product::factory()->create(['farmer_id' => $farmer->id, 'status' => 'sold']);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$farmer->id}")->assertStatus(200);

        // Not deleted.
        $this->assertDatabaseHas('products', ['id' => $activeProduct->id]);
        $this->assertDatabaseHas('products', ['id' => $soldProduct->id]);
        // Hidden from the active/browse scope.
        $this->assertNotNull($activeProduct->fresh()->delisted_at);
        $this->assertFalse(Product::active()->whereKey($activeProduct->id)->exists());
    }

    public function test_historical_orders_still_resolve_a_delisted_products_details(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id, 'name' => 'Fresh Mangoes']);
        $order = Order::factory()->create(['product_id' => $product->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$farmer->id}")->assertStatus(200);

        $this->assertSame('Fresh Mangoes', $order->fresh()->product->name);
    }
}
