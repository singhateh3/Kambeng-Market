<?php

namespace Tests\Feature\Api;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDisputeTest extends TestCase
{
    use RefreshDatabase;

    private function disputeFor(string $disputeStatus = 'open'): Dispute
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'delivered',
        ]);

        return Dispute::factory()->create([
            'order_id' => $order->id,
            'reported_by' => $buyer->id,
            'status' => $disputeStatus,
        ]);
    }

    public function test_admin_can_list_disputes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dispute = $this->disputeFor();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/disputes');

        $response->assertStatus(200)->assertJsonPath('success', true);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($dispute->id));
    }

    public function test_admin_can_filter_disputes_by_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $open = $this->disputeFor('open');
        $resolved = $this->disputeFor('resolved');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/disputes?status=open');

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($open->id));
        $this->assertFalse($ids->contains($resolved->id));
    }

    public function test_non_admin_cannot_access_admin_dispute_endpoints(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $this->getJson('/api/admin/disputes')->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_admin_dispute_endpoints(): void
    {
        $this->getJson('/api/admin/disputes')->assertStatus(401);
    }

    public function test_admin_can_move_open_dispute_to_under_review(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dispute = $this->disputeFor('open');
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'under_review',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.status', 'under_review');
        $this->assertDatabaseHas('disputes', ['id' => $dispute->id, 'status' => 'under_review']);
    }

    public function test_admin_can_resolve_dispute_under_review(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dispute = $this->disputeFor('under_review');
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'resolved',
            'admin_note' => 'Refund issued to buyer.',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.status', 'resolved');
    }

    public function test_resolution_persists_admin_note_reviewed_by_and_reviewed_at(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dispute = $this->disputeFor('under_review');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'resolved',
            'admin_note' => 'Refund issued to buyer.',
        ])->assertStatus(200);

        $dispute->refresh();
        $this->assertSame('resolved', $dispute->status);
        $this->assertSame('Refund issued to buyer.', $dispute->admin_note);
        $this->assertSame($admin->id, $dispute->reviewed_by);
        $this->assertNotNull($dispute->reviewed_at);
    }

    public function test_admin_can_reject_dispute_under_review(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dispute = $this->disputeFor('under_review');
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'rejected',
            'admin_note' => 'No evidence of an issue.',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.status', 'rejected');
        $dispute->refresh();
        $this->assertNotNull($dispute->reviewed_by);
        $this->assertNotNull($dispute->reviewed_at);
    }

    public function test_resolving_requires_admin_note(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dispute = $this->disputeFor('under_review');
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'resolved',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('admin_note');
    }

    public function test_cannot_resolve_directly_from_open_without_under_review(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dispute = $this->disputeFor('open');
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'resolved',
            'admin_note' => 'Skipping review.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('disputes', ['id' => $dispute->id, 'status' => 'open']);
    }

    public function test_cannot_transition_out_of_a_resolved_dispute(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dispute = $this->disputeFor('resolved');
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'under_review',
        ]);

        $response->assertStatus(422);
    }

    public function test_invalid_status_value_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dispute = $this->disputeFor('open');
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'not_a_real_status',
        ]);

        $response->assertStatus(422);
    }

    public function test_dispute_resolution_notifies_the_reporting_buyer(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dispute = $this->disputeFor('under_review');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/disputes/{$dispute->id}/status", [
            'status' => 'resolved',
            'admin_note' => 'Refund issued.',
        ])->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $dispute->reported_by,
            'type' => 'dispute_resolved',
        ]);
    }
}
