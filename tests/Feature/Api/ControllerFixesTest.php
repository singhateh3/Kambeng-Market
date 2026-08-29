<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ControllerFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_own_profile_via_profile_controller_show(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/user');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.email', $admin->email);
    }

    public function test_farmer_verification_request_succeeds_without_fatal_error(): void
    {
        $farmer = User::factory()->create([
            'role' => 'farmer',
            'verification_status' => 'rejected',
        ]);
        Sanctum::actingAs($farmer);

        $response = $this->postJson('/api/farmer/request-verification');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame('pending', $farmer->fresh()->verification_status);
    }

    public function test_farmer_can_submit_verification_via_profile_endpoint(): void
    {
        $farmer = User::factory()->create([
            'role' => 'farmer',
            'verification_status' => 'rejected',
        ]);
        Sanctum::actingAs($farmer);

        $response = $this->postJson('/api/farmer/profile/verify');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame('pending', $farmer->fresh()->verification_status);
    }

    public function test_farmer_can_upload_avatar(): void
    {
        Storage::fake('public');

        $farmer = User::factory()->create(['role' => 'farmer', 'avatar' => null]);
        Sanctum::actingAs($farmer);

        $response = $this->postJson('/api/farmer/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Avatar updated successfully');

        $this->assertNotNull($farmer->fresh()->avatar);
        Storage::disk('public')->assertExists($farmer->fresh()->avatar);
    }

    public function test_admin_order_status_update_no_longer_fatal_errors(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = Order::factory()->create(['status' => 'pending']);
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'status' => 'confirmed',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertSame('confirmed', $order->fresh()->status);
    }
}
