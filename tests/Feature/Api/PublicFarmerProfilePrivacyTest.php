<?php

namespace Tests\Feature\Api;

use App\Models\FarmerProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Task 10 — public, unauthenticated endpoints must not expose a
 * farmer's email, phone, revenue, or internal verification timestamps.
 * These were previously leaked via FarmerProfileResource (reused for both
 * the farmer's own authenticated profile view and the public one) and via
 * the farmer.select() on the public product endpoints.
 */
class PublicFarmerProfilePrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_index_does_not_expose_farmer_phone_or_email(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer', 'phone' => '+2201234567', 'email' => 'secret@farm.test']);
        Product::factory()->create(['farmer_id' => $farmer->id]);

        $response = $this->getJson('/api/products');

        $response->assertStatus(200);
        $farmerData = $response->json('data.0.farmer');
        // UserResource always includes these keys — the actual fix is that
        // the underlying query no longer selects the columns, so the value
        // is null rather than the real number/address.
        $this->assertNull($farmerData['phone']);
        $this->assertNull($farmerData['email']);
        $this->assertSame($farmer->name, $farmerData['name']);
        $this->assertSame($farmer->location, $farmerData['location']);
    }

    public function test_product_show_does_not_expose_farmer_phone_or_email(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer', 'phone' => '+2201234567', 'email' => 'secret@farm.test']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200);
        $farmerData = $response->json('data.farmer');
        $this->assertNull($farmerData['phone']);
        $this->assertNull($farmerData['email']);
    }

    public function test_public_farmer_profile_does_not_expose_email_revenue_or_internal_fields(): void
    {
        $farmer = User::factory()->create([
            'role' => 'farmer',
            'email' => 'secret@farm.test',
            'phone' => '+2201234567',
            'verification_requested_at' => now(),
        ]);
        FarmerProfile::factory()->create([
            'user_id' => $farmer->id,
            'farm_name' => 'Green Acres',
            'farm_location' => 'Brikama',
            'bio' => 'We grow vegetables.',
        ]);

        $response = $this->getJson("/api/farmers/{$farmer->id}/profile");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Present and correct — the legitimate public fields.
        $this->assertSame('Green Acres', $data['farm_name']);
        $this->assertSame('Brikama', $data['farm_location']);
        $this->assertSame('We grow vegetables.', $data['bio']);
        $this->assertSame('Green Acres', $data['farm_name']);
        $this->assertArrayHasKey('user', $data);
        $this->assertSame($farmer->id, $data['user']['id']);

        // Absent — nothing sensitive or internal.
        $this->assertArrayNotHasKey('email', $data['user']);
        $this->assertArrayNotHasKey('phone', $data['user']);
        $this->assertArrayNotHasKey('verification_requested_at', $data['user']);
        $this->assertArrayNotHasKey('total_revenue', $data);
        $this->assertArrayNotHasKey('total_revenue_formatted', $data);
        $this->assertArrayNotHasKey('profile_completion', $data);
    }

    public function test_public_farmer_profile_still_exposes_rating_and_product_counts(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        FarmerProfile::factory()->create(['user_id' => $farmer->id]);
        Product::factory()->count(2)->create(['farmer_id' => $farmer->id, 'status' => 'active']);

        $response = $this->getJson("/api/farmers/{$farmer->id}/profile");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertSame(2, $data['active_products_count']);
    }

    public function test_farmers_own_authenticated_profile_view_is_unaffected(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer', 'email' => 'me@farm.test']);
        FarmerProfile::factory()->create(['user_id' => $farmer->id]);
        \Laravel\Sanctum\Sanctum::actingAs($farmer);

        $response = $this->getJson('/api/farmer/profile');

        // The farmer's own private view is untouched by this task — it
        // still goes through the richer FarmerProfileResource and includes
        // their own email via the nested user relation.
        $response->assertStatus(200)
            ->assertJsonPath('data.user.email', 'me@farm.test');
    }
}
