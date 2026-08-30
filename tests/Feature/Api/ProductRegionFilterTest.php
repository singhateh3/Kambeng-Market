<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductRegionFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_region_filter_matches_case_insensitively(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer', 'location' => 'Brikama']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id, 'name' => 'Cassava']);

        $other = User::factory()->create(['role' => 'farmer', 'location' => 'Farafenni']);
        Product::factory()->create(['farmer_id' => $other->id, 'name' => 'Millet']);

        $response = $this->getJson('/api/products?region=brikama');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($product->id));
        $this->assertCount(1, $ids);
    }

    public function test_region_filter_combined_with_category(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer', 'location' => 'Brikama']);
        $matching = Product::factory()->create([
            'farmer_id' => $farmer->id,
            'category' => 'vegetables',
        ]);
        $wrongCategory = Product::factory()->create([
            'farmer_id' => $farmer->id,
            'category' => 'fruits',
        ]);

        $response = $this->getJson('/api/products?region=Brikama&category=vegetables');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($matching->id));
        $this->assertFalse($ids->contains($wrongCategory->id));
    }

    public function test_region_filter_combined_with_search(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer', 'location' => 'Brikama']);
        $matching = Product::factory()->create([
            'farmer_id' => $farmer->id,
            'name' => 'Sweet Cassava',
        ]);
        $wrongName = Product::factory()->create([
            'farmer_id' => $farmer->id,
            'name' => 'Millet',
        ]);

        $response = $this->getJson('/api/products?region=Brikama&search=cassava');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($matching->id));
        $this->assertFalse($ids->contains($wrongName->id));
    }

    public function test_region_filter_with_no_matches_returns_empty_result(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer', 'location' => 'Brikama']);
        Product::factory()->create(['farmer_id' => $farmer->id]);

        $response = $this->getJson('/api/products?region=NoSuchRegionAnywhere');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_regions_endpoint_returns_distinct_locations_of_farmers_with_active_products(): void
    {
        $brikama = User::factory()->create(['role' => 'farmer', 'location' => 'Brikama']);
        Product::factory()->create(['farmer_id' => $brikama->id]);

        // Same location as $brikama — must not produce a duplicate entry.
        $brikamaToo = User::factory()->create(['role' => 'farmer', 'location' => 'Brikama']);
        Product::factory()->create(['farmer_id' => $brikamaToo->id]);

        // Farmer with no products at all — must not appear.
        User::factory()->create(['role' => 'farmer', 'location' => 'Basse']);

        // Farmer whose only product is sold (inactive) — must not appear.
        $sold = User::factory()->create(['role' => 'farmer', 'location' => 'Farafenni']);
        Product::factory()->create(['farmer_id' => $sold->id, 'status' => 'sold']);

        $response = $this->getJson('/api/products/regions');

        $response->assertStatus(200);
        $regions = $response->json('data');
        $this->assertContains('Brikama', $regions);
        $this->assertNotContains('Basse', $regions);
        $this->assertNotContains('Farafenni', $regions);
        $this->assertCount(1, array_keys($regions, 'Brikama'));
    }
}
