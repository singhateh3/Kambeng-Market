<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the P2 follow-up audit: nine endpoints where an inline
 * $request->validate() call sat inside a try block whose only catch was a
 * generic catch (\Exception $e) — meaning a real validation failure (a
 * missing required field, an invalid enum value, etc.) was being converted
 * into a 500 "Error ..." response instead of the standard 422 validation
 * shape every other validated endpoint in this app returns. Each fixed
 * endpoint got the same specific catch (\Illuminate\Validation\
 * ValidationException $e) placed before the existing generic catch,
 * mirroring the pattern already used elsewhere (OrderController::store(),
 * ProfileController::update(), etc.).
 *
 * ProductController::updateQuantity() was also fixed but has no route in
 * routes/api.php at all (dead/unreachable code) — not testable via HTTP,
 * so it's not covered here; see the audit report for that note.
 */
class ValidationErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    // ---- AuthController::forgotPassword() ----

    public function test_forgot_password_missing_email_returns_a_validation_error_not_a_server_error(): void
    {
        $this->postJson('/api/forgot-password', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_forgot_password_with_a_valid_email_still_succeeds(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(200);
    }

    // ---- AdminFarmerVerificationController::approve() ----

    public function test_farmer_approve_invalid_notes_type_returns_a_validation_error_not_a_server_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($admin);

        // 'notes' must be a string — an array is not.
        $this->postJson("/api/admin/farmers/verification/{$farmer->id}/approve", ['notes' => ['not', 'a', 'string']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    public function test_farmer_approve_with_valid_input_still_succeeds(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/farmers/verification/{$farmer->id}/approve", ['notes' => 'Looks good'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    // ---- AdminFarmerVerificationController::reject() ----

    public function test_farmer_reject_missing_reason_returns_a_validation_error_not_a_server_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/farmers/verification/{$farmer->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_farmer_reject_with_valid_input_still_succeeds(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/farmers/verification/{$farmer->id}/reject", ['reason' => 'Documents unclear'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    // ---- AdminFarmerVerificationController::uploadDocument() ----

    public function test_upload_document_missing_fields_returns_a_validation_error_not_a_server_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/farmers/verification/{$farmer->id}/upload-document", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document', 'type']);
    }

    public function test_upload_document_with_valid_input_still_succeeds(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        \App\Models\FarmerProfile::factory()->create(['user_id' => $farmer->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/farmers/verification/{$farmer->id}/upload-document", [
            'document' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
            'type' => 'verification_document',
        ])->assertStatus(200)->assertJsonPath('success', true);
    }

    // ---- AdminUserController::updateRole() ----

    public function test_update_role_invalid_value_returns_a_validation_error_not_a_server_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/users/{$user->id}/role", ['role' => 'not-a-real-role'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_update_role_with_valid_value_still_succeeds(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/users/{$user->id}/role", ['role' => 'farmer'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    // ---- AdminUserController::toggleStatus() ----

    public function test_toggle_status_missing_field_returns_a_validation_error_not_a_server_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/toggle-status", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_toggle_status_with_valid_value_still_succeeds(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/toggle-status", ['is_active' => false])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    // ---- ProductController::addPhotos() ----

    public function test_add_photos_missing_field_returns_a_validation_error_not_a_server_error(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/products/{$product->id}/photos", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos']);
    }

    public function test_add_photos_with_valid_input_still_succeeds(): void
    {
        Storage::fake('public');
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id, 'photos' => []]);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/products/{$product->id}/photos", [
            'photos' => [UploadedFile::fake()->image('produce.jpg')],
        ])->assertStatus(200)->assertJsonPath('success', true);
    }

    // ---- ProductController::deletePhoto() ----

    public function test_delete_photo_missing_field_returns_a_validation_error_not_a_server_error(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        Sanctum::actingAs($farmer);

        $this->deleteJson("/api/products/{$product->id}/photo", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo_url']);
    }

    public function test_delete_photo_with_valid_input_still_succeeds(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create([
            'farmer_id' => $farmer->id,
            'photos' => ['https://example.com/storage/products/one.jpg'],
        ]);
        Sanctum::actingAs($farmer);

        $this->deleteJson("/api/products/{$product->id}/photo", [
            'photo_url' => 'https://example.com/storage/products/one.jpg',
        ])->assertStatus(200)->assertJsonPath('success', true);
    }
}
