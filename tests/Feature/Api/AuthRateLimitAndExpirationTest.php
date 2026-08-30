<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the throttling added to POST /api/login, /api/register,
 * /api/forgot-password (AppServiceProvider::configureRateLimiting) and the
 * Sanctum token expiration added in config/sanctum.php. These are the real,
 * actively-used API auth endpoints — not the unused Breeze web scaffolding
 * under routes/auth.php, which already has its own separate test coverage.
 */
class AuthRateLimitAndExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_login_attempts_are_throttled(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);

        // The configured per-email+IP limit is 5/minute. Wrong credentials
        // fail validation (422) up to the limit...
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // ...and the next attempt is throttled instead, regardless of
        // whether the credentials on it are even correct.
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_legitimate_login_still_works(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password', // UserFactory's default
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonStructure(['data' => ['user', 'token', 'token_type']]);
    }

    public function test_register_endpoint_is_throttled(): void
    {
        $payload = fn (int $i) => [
            'name' => 'Test User',
            'email' => "throttle-register-{$i}@example.com",
            'phone' => '+2207000000',
            'location' => 'Banjul',
            'role' => 'buyer',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        // Configured limit is 5/hour per IP — all requests in a feature
        // test share the same client IP.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/register', $payload($i))->assertStatus(201);
        }

        $response = $this->postJson('/api/register', $payload(99));

        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_forgot_password_endpoint_is_throttled(): void
    {
        $user = User::factory()->create();

        // Configured limit is 3/hour per email+IP.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/forgot-password', ['email' => $user->email])
                ->assertStatus(200);
        }

        $response = $this->postJson('/api/forgot-password', ['email' => $user->email]);

        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_authenticated_requests_work_with_a_valid_token(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        $token = $user->createToken('auth_token')->plainTextToken;

        // Sanity check: the token authenticates immediately after issuance.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertStatus(200);

        // Sanctum checks expiration dynamically against created_at on every
        // request (see config/sanctum.php), so travelling past the
        // configured window is enough to invalidate an already-issued
        // token — no need to fabricate an expires_at value.
        $this->travel(31)->days();

        // Auth's RequestGuard caches the user it resolved for the first
        // request in-memory (see RequestGuard::user()) and won't
        // re-evaluate on a second call within the same test unless the
        // cached guard is dropped — otherwise this would still see the
        // pre-travel result instead of actually re-checking expiration.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertStatus(401);
    }
}
