<?php

namespace Tests\Feature\Api;

use App\Models\SavedFarmer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SavedFarmerTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_save_a_farmer(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($buyer);

        $response = $this->postJson("/api/saved-farmers/{$farmer->id}");

        $response->assertStatus(201)
            ->assertJsonPath('data.farmer_id', $farmer->id)
            ->assertJsonPath('data.farmer.id', $farmer->id);

        $this->assertDatabaseHas('saved_farmers', [
            'buyer_id' => $buyer->id,
            'farmer_id' => $farmer->id,
        ]);
    }

    public function test_buyer_can_retrieve_their_saved_farmers(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer1 = User::factory()->create(['role' => 'farmer']);
        $farmer2 = User::factory()->create(['role' => 'farmer']);
        SavedFarmer::factory()->create(['buyer_id' => $buyer->id, 'farmer_id' => $farmer1->id]);
        SavedFarmer::factory()->create(['buyer_id' => $buyer->id, 'farmer_id' => $farmer2->id]);

        Sanctum::actingAs($buyer);

        $response = $this->getJson('/api/saved-farmers');

        $response->assertStatus(200)->assertJsonPath('meta.total', 2);
        $ids = collect($response->json('data'))->pluck('farmer_id');
        $this->assertTrue($ids->contains($farmer1->id));
        $this->assertTrue($ids->contains($farmer2->id));
    }

    public function test_buyer_can_remove_a_saved_farmer(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        SavedFarmer::factory()->create(['buyer_id' => $buyer->id, 'farmer_id' => $farmer->id]);
        Sanctum::actingAs($buyer);

        $response = $this->deleteJson("/api/saved-farmers/{$farmer->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('saved_farmers', [
            'buyer_id' => $buyer->id,
            'farmer_id' => $farmer->id,
        ]);
    }

    public function test_removing_a_farmer_that_was_never_saved_is_idempotent(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($buyer);

        $response = $this->deleteJson("/api/saved-farmers/{$farmer->id}");

        $response->assertStatus(200);
    }

    public function test_duplicate_save_is_idempotent_not_an_error(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($buyer);

        $this->postJson("/api/saved-farmers/{$farmer->id}")->assertStatus(201);

        $response = $this->postJson("/api/saved-farmers/{$farmer->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Farmer already saved')
            ->assertJsonPath('data.farmer_id', $farmer->id);

        $this->assertDatabaseCount('saved_farmers', 1);
    }

    public function test_unauthenticated_user_is_blocked(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);

        $this->postJson("/api/saved-farmers/{$farmer->id}")->assertStatus(401);
        $this->getJson('/api/saved-farmers')->assertStatus(401);
        $this->deleteJson("/api/saved-farmers/{$farmer->id}")->assertStatus(401);
    }

    public function test_farmer_role_cannot_use_saved_farmer_endpoints(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $otherFarmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/saved-farmers/{$otherFarmer->id}")->assertStatus(403);
        $this->getJson('/api/saved-farmers')->assertStatus(403);
        $this->deleteJson("/api/saved-farmers/{$otherFarmer->id}")->assertStatus(403);
    }

    public function test_admin_role_cannot_use_saved_farmer_endpoints(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/saved-farmers/{$farmer->id}")->assertStatus(403);
    }

    public function test_buyer_cannot_manipulate_another_buyers_saved_records(): void
    {
        $buyerA = User::factory()->create(['role' => 'buyer']);
        $buyerB = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        SavedFarmer::factory()->create(['buyer_id' => $buyerA->id, 'farmer_id' => $farmer->id]);

        Sanctum::actingAs($buyerB);

        // Buyer B's list must not include buyer A's saved farmer.
        $response = $this->getJson('/api/saved-farmers');
        $response->assertStatus(200)->assertJsonPath('meta.total', 0);

        // Buyer B "removing" that farmer must not touch buyer A's row —
        // delete() is scoped to buyer_id, so this is a no-op for B.
        $this->deleteJson("/api/saved-farmers/{$farmer->id}")->assertStatus(200);
        $this->assertDatabaseHas('saved_farmers', [
            'buyer_id' => $buyerA->id,
            'farmer_id' => $farmer->id,
        ]);
    }

    public function test_saving_a_non_existent_farmer_is_rejected(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/saved-farmers/999999')->assertStatus(404);
    }

    public function test_saving_a_non_farmer_user_is_rejected(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $otherBuyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $response = $this->postJson("/api/saved-farmers/{$otherBuyer->id}");

        $response->assertStatus(422);
        $this->assertDatabaseCount('saved_farmers', 0);
    }

    public function test_buyer_cannot_save_themselves(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $response = $this->postJson("/api/saved-farmers/{$buyer->id}");

        $response->assertStatus(422);
        $this->assertDatabaseCount('saved_farmers', 0);
    }

    public function test_database_unique_constraint_prevents_duplicate_rows(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        SavedFarmer::factory()->create(['buyer_id' => $buyer->id, 'farmer_id' => $farmer->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        SavedFarmer::create(['buyer_id' => $buyer->id, 'farmer_id' => $farmer->id]);
    }

    public function test_deleting_a_farmer_cascades_saved_farmer_rows(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        SavedFarmer::factory()->create(['buyer_id' => $buyer->id, 'farmer_id' => $farmer->id]);

        $farmer->delete();

        $this->assertDatabaseMissing('saved_farmers', ['farmer_id' => $farmer->id]);
    }

    public function test_saved_farmer_response_contains_expected_farmer_data(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer', 'name' => 'Fatou Jallow', 'location' => 'Brikama']);
        Sanctum::actingAs($buyer);

        $response = $this->postJson("/api/saved-farmers/{$farmer->id}");

        $response->assertStatus(201)
            ->assertJsonPath('data.farmer.name', 'Fatou Jallow')
            ->assertJsonPath('data.farmer.location', 'Brikama')
            ->assertJsonPath('data.farmer.is_farmer', true);
    }

    public function test_saved_farmers_list_is_paginated(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        SavedFarmer::factory()->count(3)->create(['buyer_id' => $buyer->id]);
        Sanctum::actingAs($buyer);

        $response = $this->getJson('/api/saved-farmers?per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_filter_saved_farmers_list_by_farmer_id(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer1 = User::factory()->create(['role' => 'farmer']);
        $farmer2 = User::factory()->create(['role' => 'farmer']);
        SavedFarmer::factory()->create(['buyer_id' => $buyer->id, 'farmer_id' => $farmer1->id]);
        SavedFarmer::factory()->create(['buyer_id' => $buyer->id, 'farmer_id' => $farmer2->id]);
        Sanctum::actingAs($buyer);

        $response = $this->getJson("/api/saved-farmers?farmer_id={$farmer1->id}");

        $response->assertStatus(200)->assertJsonPath('meta.total', 1);
        $this->assertSame($farmer1->id, $response->json('data.0.farmer_id'));
    }
}
