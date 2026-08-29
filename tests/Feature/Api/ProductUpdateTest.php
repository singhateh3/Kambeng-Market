<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_scalar_fields(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id, 'name' => 'Old Name']);

        Sanctum::actingAs($farmer);

        $response = $this->putJson("/api/products/{$product->id}", [
            'name' => 'New Name',
            'price' => 25.50,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'New Name');

        $this->assertSame('New Name', $product->fresh()->name);
        $this->assertEquals(25.50, (float) $product->fresh()->price);
    }

    public function test_non_owner_cannot_update_product(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $otherFarmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);

        Sanctum::actingAs($otherFarmer);

        $response = $this->putJson("/api/products/{$product->id}", [
            'name' => 'Hijacked Name',
        ]);

        $response->assertStatus(403);
        $this->assertNotSame('Hijacked Name', $product->fresh()->name);
    }

    public function test_unauthenticated_user_cannot_update_product(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);

        $response = $this->putJson("/api/products/{$product->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(401);
    }

    public function test_removals_are_processed_before_additions(): void
    {
        Storage::fake('public');

        // Product::getPhotosAttribute() converts stored relative paths into full
        // URLs (prefixed with config('app.url')) on every read — the same contract
        // the existing, already-working ProductController::deletePhoto() relies on.
        // A real client removes a photo by resubmitting the exact URL it was given,
        // so the test mirrors that rather than the raw stored path.
        $appUrl = config('app.url');

        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create([
            'farmer_id' => $farmer->id,
            'photos' => ['/storage/products/keep.jpg', '/storage/products/remove.jpg'],
        ]);

        Sanctum::actingAs($farmer);

        $response = $this->put("/api/products/{$product->id}", [
            'remove_photos' => ["{$appUrl}/storage/products/remove.jpg"],
            'photos' => [UploadedFile::fake()->image('new.jpg')],
        ]);

        $response->assertStatus(200);

        $photos = $product->fresh()->photos;

        $this->assertContains("{$appUrl}/storage/products/keep.jpg", $photos);
        $this->assertNotContains("{$appUrl}/storage/products/remove.jpg", $photos);
        $this->assertCount(2, $photos, 'Expected the kept photo plus the newly uploaded one.');
    }

    public function test_partial_update_does_not_clear_unvalidated_fields(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create([
            'farmer_id' => $farmer->id,
            'category' => 'vegetables',
            'description' => 'Original description',
        ]);

        Sanctum::actingAs($farmer);

        $response = $this->putJson("/api/products/{$product->id}", [
            'name' => 'Renamed Only',
        ]);

        $response->assertStatus(200);

        $fresh = $product->fresh();
        $this->assertSame('Renamed Only', $fresh->name);
        $this->assertSame('vegetables', $fresh->category);
        $this->assertSame('Original description', $fresh->description);
    }
}
