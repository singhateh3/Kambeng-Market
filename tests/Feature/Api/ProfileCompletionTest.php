<?php

namespace Tests\Feature\Api;

use App\Models\FarmerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the buyer -> farmer self-upgrade added for Google-sign-in profile
 * completion (CompleteProfile.jsx, frontend) — reuses PUT /user/profile
 * rather than a new endpoint, restricted to buyer -> farmer only (mirroring
 * what AuthController::register() already lets anyone choose freely at
 * signup), and must actually create the farmer_profiles row a buyer never
 * had, not silently no-op like a plain ->update() on a missing relation.
 */
class ProfileCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_upgrade_to_farmer_and_a_farmer_profile_row_is_created(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'phone' => null, 'location' => null]);
        Sanctum::actingAs($buyer);

        $response = $this->putJson('/api/user/profile', [
            'phone' => '+2207000000',
            'location' => 'Serrekunda',
            'role' => 'farmer',
            'farm_name' => 'Green Valley Farm',
            'farm_location' => 'Brikama',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.role', 'farmer')
            ->assertJsonPath('data.farmer_profile.farm_name', 'Green Valley Farm')
            ->assertJsonPath('data.farmer_profile.farm_location', 'Brikama');

        $buyer->refresh();
        $this->assertSame('farmer', $buyer->role);
        $this->assertNotNull($buyer->farmerProfile);
        $this->assertSame('Green Valley Farm', $buyer->farmerProfile->farm_name);
    }

    public function test_farm_name_and_farm_location_are_required_when_upgrading_to_farmer(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $response = $this->putJson('/api/user/profile', [
            'phone' => '+2207000000',
            'location' => 'Serrekunda',
            'role' => 'farmer',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['farm_name', 'farm_location']);

        $this->assertSame('buyer', $buyer->fresh()->role);
    }

    public function test_farm_name_stays_optional_for_a_plain_buyer_profile_edit(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        // No `role` in this request at all — the normal Profile page's own
        // request shape — so required_if:role,farmer must never fire.
        $response = $this->putJson('/api/user/profile', [
            'phone' => '+2207000000',
            'location' => 'Serrekunda',
        ]);

        $response->assertStatus(200);
        $this->assertSame('buyer', $buyer->fresh()->role);
    }

    public function test_farmer_cannot_be_downgraded_to_buyer_through_this_endpoint(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        FarmerProfile::factory()->create(['user_id' => $farmer->id]);
        Sanctum::actingAs($farmer);

        $response = $this->putJson('/api/user/profile', ['role' => 'buyer']);

        $response->assertStatus(422);
        $this->assertSame('farmer', $farmer->fresh()->role);
    }

    public function test_buyer_cannot_self_promote_to_admin_through_this_endpoint(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        // 'admin' isn't even in Rule::in(['buyer', 'farmer']) — confirms the
        // field-level whitelist, independent of the controller's own guard.
        $response = $this->putJson('/api/user/profile', ['role' => 'admin']);

        $response->assertStatus(422);
        $this->assertSame('buyer', $buyer->fresh()->role);
    }

    public function test_existing_farmer_can_still_edit_their_farm_details_without_sending_role(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $profile = FarmerProfile::factory()->create([
            'user_id' => $farmer->id,
            'farm_name' => 'Old Name',
            'farm_location' => 'Old Location',
            'bio' => 'Old bio',
        ]);
        Sanctum::actingAs($farmer);

        $response = $this->putJson('/api/user/profile', ['bio' => 'New bio']);

        $response->assertStatus(200)->assertJsonPath('data.farmer_profile.bio', 'New bio');

        $profile->refresh();
        $this->assertSame('New bio', $profile->bio);
        // Fields not sent in this request are left untouched.
        $this->assertSame('Old Name', $profile->farm_name);
        $this->assertSame('Old Location', $profile->farm_location);
    }

    public function test_re_submitting_the_same_role_is_a_harmless_no_op(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer', 'phone' => null]);
        FarmerProfile::factory()->create([
            'user_id' => $farmer->id,
            'farm_name' => 'Green Valley Farm',
            'farm_location' => 'Brikama',
        ]);
        Sanctum::actingAs($farmer);

        $response = $this->putJson('/api/user/profile', [
            'phone' => '+2207000000',
            'role' => 'farmer',
            'farm_name' => 'Green Valley Farm',
            'farm_location' => 'Brikama',
        ]);

        $response->assertStatus(200);
        $this->assertSame('farmer', $farmer->fresh()->role);
        $this->assertSame('+2207000000', $farmer->fresh()->phone);
    }
}
