<?php

// app/Services/Auth/JwtProviderVerifier.php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Shared JWT-verification plumbing for Google and Apple Sign-In. Both
 * providers hand the frontend a signed identity token (a JWT) whose
 * signature must be checked against the provider's own published public
 * keys (JWKS) before any claim inside it (sub/email/...) can be trusted —
 * this is the one thing that actually proves a token came from Google/
 * Apple and wasn't forged by an attacker POSTing arbitrary claims
 * straight to our backend. Signature verification (via firebase/php-jwt)
 * plus issuer/audience/expiry checks all happen here; subclasses only
 * supply the provider-specific JWKS URL, issuer(s), and audience.
 */
abstract class JwtProviderVerifier
{
    abstract protected function jwksUrl(): string;

    abstract protected function jwksCacheKey(): string;

    /** @return string[] */
    abstract protected function expectedIssuers(): array;

    abstract protected function expectedAudience(): ?string;

    abstract protected function providerName(): string;

    /**
     * @return array{sub: string, email: ?string, email_verified: bool, name: ?string, raw: array}
     *
     * @throws RuntimeException on any verification failure — signature,
     *         issuer, audience, missing subject, or expiry (checked by
     *         JWT::decode() itself). Callers must treat this as "not a
     *         trustworthy identity" and never fall back to reading claims
     *         out of an unverified token.
     */
    public function verify(string $idToken): array
    {
        if (trim($idToken) === '') {
            throw new RuntimeException('Missing identity token.');
        }

        $keySet = $this->fetchJwks();

        try {
            $decoded = JWT::decode($idToken, JWK::parseKeySet($keySet));
        } catch (Throwable $e) {
            throw new RuntimeException("Invalid {$this->providerName()} identity token: " . $e->getMessage(), 0, $e);
        }

        $claims = (array) $decoded;

        if (!in_array($claims['iss'] ?? null, $this->expectedIssuers(), true)) {
            throw new RuntimeException("Unexpected {$this->providerName()} token issuer.");
        }

        $audience = $this->expectedAudience();
        if ($audience !== null && ($claims['aud'] ?? null) !== $audience) {
            throw new RuntimeException("Unexpected {$this->providerName()} token audience.");
        }

        if (empty($claims['sub'])) {
            throw new RuntimeException("{$this->providerName()} token is missing its subject (sub) claim.");
        }

        return [
            'sub' => (string) $claims['sub'],
            'email' => $claims['email'] ?? null,
            // Google sends a real JSON boolean; Apple sends the string
            // "true"/"false" — normalize both the same way.
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name' => $claims['name'] ?? null,
            'raw' => $claims,
        ];
    }

    /** @return array<string, mixed> */
    protected function fetchJwks(): array
    {
        return Cache::remember($this->jwksCacheKey(), 3600, function () {
            $response = Http::get($this->jwksUrl());
            $response->throw();

            return $response->json();
        });
    }
}
