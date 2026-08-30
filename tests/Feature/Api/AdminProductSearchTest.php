<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_search_matches_product_name_case_insensitively(): void
    {
        $this->actingAsAdmin();
        $product = Product::factory()->create(['name' => 'Sweet Cassava']);
        $other = Product::factory()->create(['name' => 'Millet']);

        $response = $this->getJson('/api/admin/products?search=cassava');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($product->id));
        $this->assertFalse($ids->contains($other->id));
    }

    public function test_search_matches_category_and_description_case_insensitively(): void
    {
        $this->actingAsAdmin();
        $byCategory = Product::factory()->create(['category' => 'Vegetables', 'name' => 'Okra']);
        $byDescription = Product::factory()->create(['description' => 'Organically grown Cassava root.']);
        $unrelated = Product::factory()->create(['category' => 'grains', 'description' => 'Plain rice.', 'name' => 'Rice']);

        $response = $this->getJson('/api/admin/products?search=vegetables');
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($byCategory->id));
        $this->assertFalse($ids->contains($unrelated->id));

        $response = $this->getJson('/api/admin/products?search=cassava');
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($byDescription->id));
        $this->assertFalse($ids->contains($unrelated->id));
    }

    public function test_search_matches_farmer_name_email_and_location_case_insensitively(): void
    {
        $this->actingAsAdmin();

        $farmer = User::factory()->create([
            'role' => 'farmer',
            'name' => 'Fatou Jallow',
            'email' => 'fatou@example.com',
            'location' => 'Brikama',
        ]);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);

        $otherFarmer = User::factory()->create(['role' => 'farmer', 'name' => 'Lamin Ceesay', 'location' => 'Basse']);
        $other = Product::factory()->create(['farmer_id' => $otherFarmer->id]);

        $byName = $this->getJson('/api/admin/products?search=fatou');
        $this->assertTrue(collect($byName->json('data'))->pluck('id')->contains($product->id));
        $this->assertFalse(collect($byName->json('data'))->pluck('id')->contains($other->id));

        $byEmail = $this->getJson('/api/admin/products?search=FATOU@EXAMPLE.COM');
        $this->assertTrue(collect($byEmail->json('data'))->pluck('id')->contains($product->id));

        $byLocation = $this->getJson('/api/admin/products?search=brikama');
        $this->assertTrue(collect($byLocation->json('data'))->pluck('id')->contains($product->id));
        $this->assertFalse(collect($byLocation->json('data'))->pluck('id')->contains($other->id));
    }

    public function test_existing_admin_filters_still_work_after_search_fix(): void
    {
        $this->actingAsAdmin();

        $farmer = User::factory()->create(['role' => 'farmer']);
        $active = Product::factory()->create(['farmer_id' => $farmer->id, 'status' => 'active']);
        $sold = Product::factory()->create(['farmer_id' => $farmer->id, 'status' => 'sold']);

        $response = $this->getJson('/api/admin/products?status=active');
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($sold->id));

        $response = $this->getJson("/api/admin/products?farmer_id={$farmer->id}");
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertTrue($ids->contains($sold->id));
    }

    public function test_admin_products_endpoint_rejects_unauthenticated_requests(): void
    {
        $response = $this->getJson('/api/admin/products');

        $response->assertStatus(401);
    }

    public function test_admin_products_endpoint_rejects_non_admin_users(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $response = $this->getJson('/api/admin/products');

        $response->assertStatus(403);
    }
}
