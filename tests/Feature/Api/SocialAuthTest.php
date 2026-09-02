<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\IdentityTokenFactory;
use Tests\TestCase;

/**
 * Covers Task 12's Google/Apple Sign-In architecture. Every test signs a
 * REAL RS256 JWT against a throwaway, test-generated RSA keypair and
 * Http::fake()s the provider's JWKS endpoint to return the matching
 * public key — so these exercise JwtProviderVerifier's actual signature/
 * issuer/audience verification, not a mocked-away stand-in. No real
 * Google/Apple credentials are used or required anywhere here.
 */
class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-google-client-id',
            'services.apple.services_id' => 'test-apple-services-id',
        ]);

        // Each test gets a fresh JWKS cache — the cache is keyed process-
        // wide, not per-test-database, so a stale entry from another test
        // run could otherwise mask a real Http::fake() mismatch.
        Cache::forget('auth:google:jwks');
        Cache::forget('auth:apple:jwks');
    }

    private function fakeGoogleJwks(): void
    {
        Http::fake([
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response(IdentityTokenFactory::jwks()),
        ]);
    }

    private function fakeAppleJwks(): void
    {
        Http::fake([
            'https://appleid.apple.com/auth/keys' => Http::response(IdentityTokenFactory::jwks()),
        ]);
    }

    public function test_google_first_time_sign_in_creates_a_user_and_returns_a_token(): void
    {
        $this->fakeGoogleJwks();

        $token = IdentityTokenFactory::signedToken([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'sub' => 'google-sub-1',
            'email' => 'new-google-user@example.com',
            'email_verified' => true,
            'name' => 'New Google User',
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => $token]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.email', 'new-google-user@example.com')
            ->assertJsonPath('data.token_type', 'Bearer');
        $this->assertNotEmpty($response->json('data.token'));

        $user = User::where('email', 'new-google-user@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('google', $user->provider);
        $this->assertSame('google-sub-1', $user->provider_id);
        $this->assertSame('buyer', $user->role);
        $this->assertNull($user->password);
    }

    public function test_google_returning_user_signs_in_via_existing_provider_link(): void
    {
        $this->fakeGoogleJwks();

        $existing = User::factory()->create([
            'email' => 'returning@example.com',
            'provider' => 'google',
            'provider_id' => 'google-sub-2',
            'password' => null,
        ]);

        $token = IdentityTokenFactory::signedToken([
            'aud' => 'test-google-client-id',
            'sub' => 'google-sub-2',
            'email' => 'returning@example.com',
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => $token]);

        $response->assertStatus(200)->assertJsonPath('data.user.id', $existing->id);
        $this->assertSame(1, User::where('email', 'returning@example.com')->count());
    }

    public function test_verified_email_auto_links_to_existing_password_account(): void
    {
        $this->fakeGoogleJwks();

        $existing = User::factory()->create([
            'email' => 'linkable@example.com',
            'password' => Hash::make('some-password'),
            'provider' => null,
            'provider_id' => null,
        ]);

        $token = IdentityTokenFactory::signedToken([
            'aud' => 'test-google-client-id',
            'sub' => 'google-sub-3',
            'email' => 'linkable@example.com',
            'email_verified' => true,
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => $token]);

        $response->assertStatus(200)->assertJsonPath('data.user.id', $existing->id);

        $existing->refresh();
        $this->assertSame('google', $existing->provider);
        $this->assertSame('google-sub-3', $existing->provider_id);
        // The account's original password must survive linking — this is
        // an additive link, not a takeover/replace.
        $this->assertTrue(Hash::check('some-password', $existing->password));
    }

    public function test_unverified_email_does_not_auto_link_to_existing_account(): void
    {
        $this->fakeGoogleJwks();

        $existing = User::factory()->create([
            'email' => 'victim@example.com',
            'password' => Hash::make('victim-password'),
            'provider' => null,
            'provider_id' => null,
        ]);

        $token = IdentityTokenFactory::signedToken([
            'aud' => 'test-google-client-id',
            'sub' => 'attacker-sub',
            'email' => 'victim@example.com',
            'email_verified' => false,
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => $token]);

        // Rejected, not silently linked and not a 500 from a duplicate-
        // email DB constraint violation either.
        $response->assertStatus(409);

        $existing->refresh();
        $this->assertNull($existing->provider);
        $this->assertNull($existing->provider_id);
        $this->assertSame(1, User::where('email', 'victim@example.com')->count());
    }

    public function test_token_with_invalid_signature_is_rejected(): void
    {
        $this->fakeGoogleJwks();

        // Well-formed JWT structure, but signed with a DIFFERENT keypair
        // than the one advertised in the faked JWKS — must fail signature
        // verification, not just "look" valid.
        $otherKeyResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($otherKeyResource, $otherPrivateKeyPem);

        $forged = \Firebase\JWT\JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'sub' => 'forged-sub',
            'email' => 'forged@example.com',
            'email_verified' => true,
            'iat' => time(),
            'exp' => time() + 3600,
        ], $otherPrivateKeyPem, 'RS256', IdentityTokenFactory::kid());

        $response = $this->postJson('/api/auth/google', ['id_token' => $forged]);

        $response->assertStatus(401);
        $this->assertSame(0, User::where('email', 'forged@example.com')->count());
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->fakeGoogleJwks();

        $token = IdentityTokenFactory::signedToken([
            'aud' => 'test-google-client-id',
            'exp' => time() - 60,
        ]);

        $this->postJson('/api/auth/google', ['id_token' => $token])->assertStatus(401);
    }

    public function test_wrong_audience_token_is_rejected(): void
    {
        $this->fakeGoogleJwks();

        $token = IdentityTokenFactory::signedToken([
            'aud' => 'some-other-clients-id',
        ]);

        $this->postJson('/api/auth/google', ['id_token' => $token])->assertStatus(401);
    }

    public function test_missing_id_token_is_a_validation_error(): void
    {
        $this->postJson('/api/auth/google', [])->assertStatus(422);
    }

    public function test_apple_sign_in_verifies_against_apples_issuer_and_audience(): void
    {
        $this->fakeAppleJwks();

        $token = IdentityTokenFactory::signedToken([
            'iss' => 'https://appleid.apple.com',
            'aud' => 'test-apple-services-id',
            'sub' => 'apple-sub-1',
            'email' => 'apple-user@privaterelay.appleid.com',
            // Apple sends this as the STRING "true", not a JSON boolean —
            // confirms the normalization in JwtProviderVerifier::verify().
            'email_verified' => 'true',
        ]);

        $response = $this->postJson('/api/auth/apple', ['id_token' => $token]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.email', 'apple-user@privaterelay.appleid.com');

        $user = User::where('email', 'apple-user@privaterelay.appleid.com')->first();
        $this->assertSame('apple', $user->provider);
        $this->assertSame('apple-sub-1', $user->provider_id);
    }

    public function test_apple_first_login_captures_name_from_separate_user_object(): void
    {
        $this->fakeAppleJwks();

        // Apple's identity token never carries a name claim (unlike
        // Google's) — this is what the frontend actually receives, and
        // what SocialAuthController must combine with the separately-
        // submitted `name` object to set a new account's display name.
        $token = IdentityTokenFactory::signedToken([
            'iss' => 'https://appleid.apple.com',
            'aud' => 'test-apple-services-id',
            'sub' => 'apple-sub-first-login',
            'email' => 'first-timer@example.com',
            'email_verified' => 'true',
            'name' => null,
        ]);

        $response = $this->postJson('/api/auth/apple', [
            'id_token' => $token,
            'name' => ['firstName' => 'Awa', 'lastName' => 'Jallow'],
        ]);

        $response->assertStatus(200);

        $user = User::where('email', 'first-timer@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Awa Jallow', $user->name);
    }

    public function test_apple_returning_login_without_name_does_not_change_existing_name(): void
    {
        $this->fakeAppleJwks();

        $existing = User::factory()->create([
            'name' => 'Established Name',
            'email' => 'returning-apple@example.com',
            'provider' => 'apple',
            'provider_id' => 'apple-sub-returning',
            'password' => null,
        ]);

        // Apple only sends the `user` (name) object on the very first
        // authorization — every later sign-in genuinely has no name field
        // to send, which is exactly what's simulated here.
        $token = IdentityTokenFactory::signedToken([
            'iss' => 'https://appleid.apple.com',
            'aud' => 'test-apple-services-id',
            'sub' => 'apple-sub-returning',
            'email' => 'returning-apple@example.com',
            'name' => null,
        ]);

        $response = $this->postJson('/api/auth/apple', ['id_token' => $token]);

        $response->assertStatus(200)->assertJsonPath('data.user.id', $existing->id);
        $this->assertSame('Established Name', $existing->fresh()->name);
    }

    public function test_apple_supplied_name_is_never_used_when_linking_to_an_existing_account(): void
    {
        $this->fakeAppleJwks();

        $existing = User::factory()->create([
            'name' => 'Original Password User',
            'email' => 'linkable-apple@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('some-password'),
            'provider' => null,
            'provider_id' => null,
        ]);

        // A verified-email link to an EXISTING account — even if a name
        // happens to be submitted alongside it, it must never overwrite
        // the existing account's own name. Only User::create() (a
        // genuinely brand-new account) ever applies $firstLoginName.
        $token = IdentityTokenFactory::signedToken([
            'iss' => 'https://appleid.apple.com',
            'aud' => 'test-apple-services-id',
            'sub' => 'apple-sub-link-attempt',
            'email' => 'linkable-apple@example.com',
            'email_verified' => 'true',
            'name' => null,
        ]);

        $response = $this->postJson('/api/auth/apple', [
            'id_token' => $token,
            'name' => ['firstName' => 'Someone', 'lastName' => 'Else'],
        ]);

        $response->assertStatus(200)->assertJsonPath('data.user.id', $existing->id);
        $existing->refresh();
        $this->assertSame('Original Password User', $existing->name);
        $this->assertSame('apple', $existing->provider);
    }

    public function test_social_only_account_fails_password_login_safely(): void
    {
        // Task 12 review — confirms the users.password nullable migration
        // doesn't crash AuthController::login() for a social-only account,
        // and that it fails with a clear, specific message rather than
        // either a 500 or the generic "incorrect credentials" text.
        User::factory()->create([
            'email' => 'social-only@example.com',
            'password' => null,
            'provider' => 'google',
            'provider_id' => 'google-sub-social-only',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'social-only@example.com',
            'password' => 'whatever-someone-typed',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Google or Apple', $response->json('errors.email.0'));
    }

    public function test_apple_token_rejected_by_google_endpoint_and_vice_versa(): void
    {
        $this->fakeGoogleJwks();

        // An Apple-issued token (wrong issuer) presented to the Google
        // endpoint must not be accepted just because it's otherwise
        // well-formed and signed by a key the (Google) JWKS happens to
        // recognize in this test's shared keypair setup.
        $token = IdentityTokenFactory::signedToken([
            'iss' => 'https://appleid.apple.com',
            'aud' => 'test-google-client-id',
        ]);

        $this->postJson('/api/auth/google', ['id_token' => $token])->assertStatus(401);
    }
}
