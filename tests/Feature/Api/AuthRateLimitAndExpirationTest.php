<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the throttling added to POST /api/login, /api/register,
 * /api/forgot-password (AppServiceProvider::configureRateLimiting) and the
 * Sanctum token expiration configured in config/sanctum.php. These are the
 * real, actively-used API auth endpoints the SPA calls — the Breeze web
 * scaffolding under routes/auth.php (not used by the SPA, but live in
 * production) has its own separate test coverage in
 * tests/Feature/Auth/AuthenticationTest.php and
 * tests/Feature/Auth/WebAuthThrottlingTest.php.
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

    /**
     * Both boundary tests read config('sanctum.expiration') rather than
     * hardcoding "10080" — this is the actual configured window (7 days as
     * of this test, see config/sanctum.php), and reading it live means
     * these tests keep testing the real boundary even if that value is
     * retuned again later, rather than silently testing a stale number.
     */
    public function test_token_just_inside_the_expiration_window_remains_valid(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $expirationMinutes = config('sanctum.expiration');

        // Sanctum checks expiration dynamically against created_at on every
        // request (see config/sanctum.php), so travelling forward is enough
        // to exercise this — no need to fabricate an expires_at value.
        $this->travel($expirationMinutes - 1)->minutes();

        // Auth's RequestGuard caches the user it resolved for the first
        // request in-memory (see RequestGuard::user()) and won't
        // re-evaluate on a second call within the same test unless the
        // cached guard is dropped — otherwise this would still see the
        // pre-travel result instead of actually re-checking expiration.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertStatus(200);
    }

    public function test_token_just_beyond_the_expiration_window_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $expirationMinutes = config('sanctum.expiration');

        $this->travel($expirationMinutes + 1)->minutes();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    /**
     * The Breeze /logout test (tests/Feature/Auth/AuthenticationTest.php)
     * exercises the unused 'web' session guard, not the Sanctum bearer
     * token the SPA actually holds — this specifically proves the real
     * API token is dead after AuthController::logout().
     */
    public function test_api_logout_revokes_the_token_used_to_log_out(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password', // UserFactory's default
        ]);
        $token = $login->json('data.token');

        // AuthController::login() authenticates via auth()->attempt(),
        // which — only inside Laravel's test client, never in real
        // production traffic (the SPA's axios instance never sends
        // cookies; see services/api.js) — leaves the 'web' session guard
        // logged in for the rest of this test. Left alone, the next
        // request would authenticate through that stateful session
        // instead of the Bearer token, and currentAccessToken() would
        // return Sanctum's placeholder TransientToken (no delete()
        // method) instead of the real PersonalAccessToken this test needs
        // to exercise. Logging the web guard out isolates the two.
        $this->app['auth']->guard('web')->logout();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/logout')
            ->assertStatus(200);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    /**
     * Documents the existing single-active-token architecture:
     * AuthController::login() calls $user->tokens()->delete() before
     * issuing a new one, so there is never more than one valid token per
     * user — a second device signing in silently signs the first one out.
     */
    public function test_second_login_revokes_the_first_devices_token(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        $credentials = ['email' => $user->email, 'password' => 'password'];

        $tokenA = $this->postJson('/api/login', $credentials)->json('data.token');
        $tokenB = $this->postJson('/api/login', $credentials)->json('data.token');

        $this->assertNotSame($tokenA, $tokenB);

        // See the matching comment in test_api_logout_revokes_the_token_used_to_log_out()
        // — isolates these Bearer-token checks from the 'web' session
        // guard auth()->attempt() left logged in, a test-client-only
        // artifact.
        $this->app['auth']->guard('web')->logout();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->getJson('/api/user')
            ->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $user->id);
    }
}
