<?php

// tests/Feature/Api/AccountActiveEnforcementTest.php
//
// Phase 3B — a deactivated account (users.deactivated_at set, see
// UserDeactivationService) must never authenticate again and must lose
// access via any already-issued Sanctum token on its very next request.
// Two enforcement points, both covered here: the login-time checks in
// AuthController::login()/SocialAuthController::handle(), and the
// EnsureAccountIsActive middleware (registered in bootstrap/app.php,
// layered into routes/api.php's two auth:sanctum groups) for a token
// issued before deactivation. The existing 7-day Sanctum expiration and
// the existing unauthenticated-response shape are both left untouched —
// confirmed here by asserting the exact same {success:false,
// code:'UNAUTHENTICATED'} shape every other 401 in this app already uses.

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IdentityTokenFactory;
use Tests\TestCase;

class AccountActiveEnforcementTest extends TestCase
{
    use RefreshDatabase;

    // --- active accounts unaffected ---

    public function test_active_user_can_still_log_in_normally(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.user.id', $user->id);
    }

    public function test_active_user_can_access_protected_routes(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($user);

        $this->getJson('/api/user')->assertStatus(200);
    }

    // --- login-time block ---

    public function test_deactivated_user_cannot_log_in_with_the_correct_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('correct-password'),
            'deactivated_at' => now(),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This account has been deactivated.');
    }

    // --- already-issued token stops working ---

    public function test_deactivated_users_existing_token_is_rejected_with_401(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($user);

        // Confirm the token works before deactivation.
        $this->getJson('/api/user')->assertStatus(200);

        $user->update(['deactivated_at' => now()]);

        $response = $this->getJson('/api/user');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_deactivated_admins_existing_token_is_rejected_on_admin_routes_too(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dashboard/statistics')->assertStatus(200);

        $admin->update(['deactivated_at' => now()]);

        $this->getJson('/api/admin/dashboard/statistics')->assertStatus(401);
    }

    public function test_unauthenticated_request_still_gets_the_same_401_shape(): void
    {
        // Confirms the middleware addition didn't change behavior for a
        // request with no token at all.
        $response = $this->getJson('/api/user');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated. Please login first.')
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    // --- social login ---

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'test-google-client-id']);
        Cache::forget('auth:google:jwks');
    }

    private function fakeGoogleJwks(): void
    {
        Http::fake([
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response(IdentityTokenFactory::jwks()),
        ]);
    }

    public function test_returning_google_identity_resolves_to_the_deactivated_account_and_is_rejected(): void
    {
        $this->fakeGoogleJwks();

        $user = User::factory()->create([
            'provider' => 'google',
            'provider_id' => 'google-sub-deactivated',
            'deactivated_at' => now(),
        ]);

        $token = IdentityTokenFactory::signedToken([
            'aud' => 'test-google-client-id',
            'sub' => 'google-sub-deactivated',
            'email' => $user->email,
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => $token]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This account has been deactivated.');

        // Not silently duplicated into a second, disconnected account.
        $this->assertSame(1, User::where('provider_id', 'google-sub-deactivated')->count());
        $this->assertSame(0, $user->tokens()->count());
    }
}
