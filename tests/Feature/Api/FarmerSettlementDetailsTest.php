<?php

namespace Tests\Feature\Api;

use App\Models\FarmerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers Task 10's production blocker #1 — before this, there was no
 * farmer-facing way to set settlement details at all, meaning every
 * payout release would fail with missing_settlement_info forever.
 */
class FarmerSettlementDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function farmerWithProfile(): User
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        FarmerProfile::factory()->create(['user_id' => $farmer->id]);
        return $farmer;
    }

    public function test_farmer_can_set_settlement_details(): void
    {
        $farmer = $this->farmerWithProfile();
        Sanctum::actingAs($farmer);

        $response = $this->putJson('/api/farmer/profile', [
            'settlement_network' => 'wave',
            'settlement_account_number' => '+2201234567',
            'settlement_beneficiary_name' => $farmer->name,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.settlement_network', 'wave')
            ->assertJsonPath('data.settlement_account_number', '+2201234567');

        $profile = $farmer->farmerProfile->fresh();
        $this->assertTrue($profile->hasSettlementDetails());
        $this->assertNotNull($profile->settlement_verified_at);
    }

    public function test_settlement_network_must_be_wave_or_afrimoney(): void
    {
        $farmer = $this->farmerWithProfile();
        Sanctum::actingAs($farmer);

        $response = $this->putJson('/api/farmer/profile', [
            'settlement_network' => 'orange_money',
            'settlement_account_number' => '+2201234567',
            'settlement_beneficiary_name' => $farmer->name,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('settlement_network');
    }

    public function test_settlement_fields_must_be_submitted_together(): void
    {
        $farmer = $this->farmerWithProfile();
        Sanctum::actingAs($farmer);

        // Only the network, missing account number and beneficiary name —
        // must not silently leave the farmer in a half-configured state.
        $response = $this->putJson('/api/farmer/profile', [
            'settlement_network' => 'wave',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['settlement_account_number', 'settlement_beneficiary_name']);
    }

    public function test_updating_unrelated_profile_fields_does_not_require_settlement_fields(): void
    {
        $farmer = $this->farmerWithProfile();
        Sanctum::actingAs($farmer);

        $response = $this->putJson('/api/farmer/profile', ['bio' => 'We grow tomatoes.']);

        $response->assertStatus(200);
    }

    public function test_farmer_cannot_set_settlement_details_for_another_farmer(): void
    {
        $farmer = $this->farmerWithProfile();
        $otherFarmer = $this->farmerWithProfile();
        Sanctum::actingAs($farmer);

        // No id in the route at all — the endpoint is unconditionally
        // scoped to $request->user()->farmerProfile, so there is no way
        // to even attempt targeting another farmer's profile through it.
        $this->putJson('/api/farmer/profile', [
            'settlement_network' => 'wave',
            'settlement_account_number' => '+2209999999',
            'settlement_beneficiary_name' => 'Attacker',
        ])->assertStatus(200);

        $this->assertDatabaseMissing('farmer_profiles', [
            'user_id' => $otherFarmer->id,
            'settlement_account_number' => '+2209999999',
        ]);
    }

    public function test_buyer_cannot_access_farmer_profile_update(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $response = $this->putJson('/api/farmer/profile', [
            'settlement_network' => 'wave',
            'settlement_account_number' => '+2201234567',
            'settlement_beneficiary_name' => 'Someone',
        ]);

        $response->assertStatus(404); // no farmerProfile exists for a buyer
    }

    public function test_settlement_account_number_is_not_exposed_in_public_or_saved_farmer_views(): void
    {
        $farmer = $this->farmerWithProfile();
        $farmer->farmerProfile->update([
            'settlement_network' => 'wave',
            'settlement_account_number' => '+2201234567',
            'settlement_beneficiary_name' => $farmer->name,
        ]);

        $publicResponse = $this->getJson("/api/farmers/{$farmer->id}/profile");
        $publicResponse->assertStatus(200);
        $this->assertArrayNotHasKey('settlement_account_number', $publicResponse->json('data'));
        $this->assertArrayNotHasKey('settlement_account_number', $publicResponse->json('data.user') ?? []);

        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);
        $this->postJson("/api/saved-farmers/{$farmer->id}")->assertStatus(201);
        $savedResponse = $this->getJson('/api/saved-farmers');
        $farmerData = $savedResponse->json('data.0.farmer');
        $this->assertArrayNotHasKey('settlement_account_number', $farmerData);
        $this->assertArrayNotHasKey('settlement_account_number', $farmerData['farmer_profile'] ?? []);
    }

    public function test_payout_release_can_obtain_current_settlement_details(): void
    {
        $farmer = $this->farmerWithProfile();
        Sanctum::actingAs($farmer);

        $this->putJson('/api/farmer/profile', [
            'settlement_network' => 'afrimoney',
            'settlement_account_number' => '+2207654321',
            'settlement_beneficiary_name' => $farmer->name,
        ])->assertStatus(200);

        $profile = $farmer->farmerProfile->fresh();
        $this->assertTrue($profile->hasSettlementDetails());
        $this->assertSame('afrimoney', $profile->settlement_network);
    }
}
