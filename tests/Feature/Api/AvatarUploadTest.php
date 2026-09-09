<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the profile-picture feature added on top of the general profile
 * endpoint (PUT /user/profile -> AuthController::updateProfile), for every
 * role — customers, farmers, and admins all share this one endpoint, none
 * of it role-gated.
 *
 * CLOUDINARY_URL is deliberately blank in phpunit.xml, so these exercise
 * CloudinaryService's local-disk fallback path (same as ProductController's
 * equivalent fallback is exercised in its own tests) rather than making a
 * real Cloudinary call — there is no existing precedent in this codebase
 * for faking the Cloudinary SDK itself, and CloudinaryService::upload()'s
 * Cloudinary branch is a thin, three-line pass-through to the SDK.
 */
class AvatarUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_buyer_can_upload_an_avatar(): void
    {
        Storage::fake('public');
        $buyer = User::factory()->create(['role' => 'buyer', 'avatar' => null]);
        Sanctum::actingAs($buyer);

        $response = $this->putJson('/api/user/profile', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($buyer->fresh()->avatar);
    }

    public function test_authenticated_farmer_can_upload_an_avatar(): void
    {
        Storage::fake('public');
        $farmer = User::factory()->create(['role' => 'farmer', 'avatar' => null]);
        Sanctum::actingAs($farmer);

        $response = $this->putJson('/api/user/profile', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($farmer->fresh()->avatar);
    }

    public function test_authenticated_admin_can_upload_an_avatar(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin', 'avatar' => null]);
        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/user/profile', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($admin->fresh()->avatar);
    }

    public function test_unauthenticated_request_cannot_upload_an_avatar(): void
    {
        $response = $this->putJson('/api/user/profile', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertStatus(401);
    }

    public function test_invalid_file_type_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/profile', [
            'avatar' => UploadedFile::fake()->create('resume.pdf', 500, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['avatar']);
        $this->assertNull($user->fresh()->avatar);
    }

    public function test_gif_is_rejected_even_though_laravels_generic_image_rule_allows_it(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/profile', [
            'avatar' => UploadedFile::fake()->image('avatar.gif'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['avatar']);
    }

    public function test_oversized_file_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // 5120 KB is the limit — 6000 KB must fail.
        $response = $this->putJson('/api/user/profile', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg')->size(6000),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['avatar']);
        $this->assertNull($user->fresh()->avatar);
    }

    public function test_user_cannot_modify_another_users_avatar(): void
    {
        Storage::fake('public');
        $attacker = User::factory()->create(['role' => 'buyer', 'avatar' => null]);
        $victim = User::factory()->create(['role' => 'buyer', 'avatar' => null]);
        Sanctum::actingAs($attacker);

        // No user id is even accepted by this endpoint — it always acts on
        // $request->user() (see AuthController::updateProfile) — so there is
        // no field to smuggle a target id through in the first place.
        $response = $this->putJson('/api/user/profile', [
            'id' => $victim->id,
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($attacker->fresh()->avatar);
        $this->assertNull($victim->fresh()->avatar);
    }

    public function test_avatar_url_is_returned_in_the_response(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['avatar' => null]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/profile', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertStatus(200);
        $url = $response->json('data.avatar');
        $this->assertNotNull($url);
        $this->assertStringContainsString('/storage/avatars/', $url);
    }

    public function test_uploading_a_new_avatar_deletes_the_old_local_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/user/profile', [
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ])->assertStatus(200);
        $firstPath = $user->fresh()->avatar;
        Storage::disk('public')->assertExists($firstPath);

        $this->putJson('/api/user/profile', [
            'avatar' => UploadedFile::fake()->image('second.jpg'),
        ])->assertStatus(200);

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($user->fresh()->avatar);
    }

    public function test_removing_an_avatar_clears_it_and_deletes_the_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/user/profile', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ])->assertStatus(200);
        $path = $user->fresh()->avatar;

        $response = $this->putJson('/api/user/profile', ['remove_avatar' => true]);

        $response->assertStatus(200)->assertJsonPath('data.avatar', null);
        $this->assertNull($user->fresh()->avatar);
        $this->assertNull($user->fresh()->avatar_public_id);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_removing_when_there_is_no_avatar_is_a_harmless_no_op(): void
    {
        $user = User::factory()->create(['avatar' => null]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/profile', ['remove_avatar' => true]);

        $response->assertStatus(200)->assertJsonPath('data.avatar', null);
    }

    public function test_removal_never_breaks_for_a_legacy_avatar_with_no_stored_public_id(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/legacy.jpg', 'fake-content');
        // A pre-Cloudinary-migration row: a local path, no avatar_public_id.
        $user = User::factory()->create(['avatar' => 'avatars/legacy.jpg', 'avatar_public_id' => null]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/profile', ['remove_avatar' => true]);

        $response->assertStatus(200);
        $this->assertNull($user->fresh()->avatar);
        Storage::disk('public')->assertMissing('avatars/legacy.jpg');
    }

    public function test_other_profile_fields_still_update_without_touching_the_avatar(): void
    {
        $user = User::factory()->create(['avatar' => null, 'phone' => '111']);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/profile', ['phone' => '+2207000000']);

        $response->assertStatus(200)->assertJsonPath('data.avatar', null);
        $this->assertSame('+2207000000', $user->fresh()->phone);
    }
}
