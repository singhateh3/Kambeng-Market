<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers the throttling added to the Breeze web scaffolding's POST
 * /register and POST /forgot-password (routes/auth.php) — live in
 * production (routes/web.php requires this file unconditionally) but not
 * used by the SPA, which authenticates through /api/* instead (see
 * tests/Feature/Api/AuthRateLimitAndExpirationTest.php). Both routes reuse
 * the SAME named limiters ('register', 'forgot-password') the API routes
 * already use — see routes/auth.php's own comment on why that's safe —
 * so these values must stay in lockstep with the API-side tests.
 *
 * POST /login is deliberately NOT covered here: Breeze's own LoginRequest
 * already enforces its own 5-attempts-per-email+IP lockout independent of
 * route middleware (app/Http/Requests/Auth/LoginRequest.php), and this task
 * did not add anything to it.
 */
class WebAuthThrottlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_registration_is_allowed_within_five_per_hour(): void
    {
        $payload = fn (int $i) => [
            'name' => 'Test User',
            'email' => "web-register-{$i}@example.com",
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        for ($i = 0; $i < 5; $i++) {
            // Breeze's RegisteredUserController::store() returns 204 (no
            // content) on success, not 201 — unlike the API's /api/register.
            $this->post('/register', $payload($i))->assertStatus(204);

            // RegisteredUserController::store() calls Auth::login($user)
            // on success, which — only inside Laravel's test client, never
            // in real traffic — would make the 'guest' middleware reject
            // every subsequent registration in this loop with a 302
            // instead of a fresh 204. Log the web guard back out so each
            // iteration is a genuinely independent, unauthenticated request.
            $this->app['auth']->guard('web')->logout();
        }
    }

    public function test_web_registration_is_throttled_beyond_five_per_hour(): void
    {
        $payload = fn (int $i) => [
            'name' => 'Test User',
            'email' => "web-register-throttle-{$i}@example.com",
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', $payload($i))->assertStatus(204);
            $this->app['auth']->guard('web')->logout();
        }

        $this->post('/register', $payload(99))->assertStatus(429);
    }

    public function test_web_forgot_password_is_throttled_per_email_and_ip(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        // Configured 'forgot-password' limit is 3/hour per email+IP. This
        // is layered on top of — and must be tested independently of —
        // Laravel's own built-in per-email password-broker cooldown
        // (config('auth.passwords.users.throttle'), 60 seconds by default):
        // without travelling past that first, the 2nd/3rd request in this
        // loop would be rejected by that unrelated, pre-existing mechanism
        // (a 302 redirect with a "Please wait before retrying." session
        // error) before ever reaching the 3-per-hour limit this test
        // actually exists to cover.
        for ($i = 0; $i < 3; $i++) {
            $this->post('/forgot-password', ['email' => $user->email])->assertStatus(200);
            $this->travel(61)->seconds();
        }

        $this->post('/forgot-password', ['email' => $user->email])->assertStatus(429);

        // The real Password::sendResetLink() call this endpoint makes
        // (unlike the API's stub) only ran for the 3 allowed requests —
        // confirms the throttle fires before the notification is sent, not
        // after.
        Notification::assertSentToTimes($user, ResetPassword::class, 3);
    }

    public function test_web_forgot_password_is_throttled_ip_wide_across_different_emails(): void
    {
        Notification::fake();

        // Configured 'forgot-password' limit is also 10/hour per IP,
        // independent of the tighter 3/hour per-email+IP limit above —
        // one request per email here stays under that per-email cap the
        // whole way, so only the IP-wide cap can be what trips.
        $users = User::factory()->count(10)->create();

        foreach ($users as $user) {
            $this->post('/forgot-password', ['email' => $user->email])->assertStatus(200);
        }

        $eleventh = User::factory()->create();
        $this->post('/forgot-password', ['email' => $eleventh->email])->assertStatus(429);
    }
}
