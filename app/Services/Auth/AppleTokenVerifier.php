<?php

// app/Services/Auth/AppleTokenVerifier.php

namespace App\Services\Auth;

/**
 * Verifies an Apple Sign In identity token against Apple's published
 * JWKS. See JwtProviderVerifier for the actual signature/issuer/audience
 * checks — this class only supplies Apple's specific values.
 *
 * Note: Apple only includes `email`/`name`-equivalent claims on a user's
 * very first authorization for this app — SocialAuthService is what's
 * responsible for persisting that at first-login time, since it cannot be
 * recovered from a later token.
 */
class AppleTokenVerifier extends JwtProviderVerifier
{
    protected function jwksUrl(): string
    {
        return 'https://appleid.apple.com/auth/keys';
    }

    protected function jwksCacheKey(): string
    {
        return 'auth:apple:jwks';
    }

    protected function expectedIssuers(): array
    {
        return ['https://appleid.apple.com'];
    }

    protected function expectedAudience(): ?string
    {
        // The web "Services ID" — the audience Apple issues the identity
        // token for in a browser-based Sign In With Apple flow.
        return config('services.apple.services_id');
    }

    protected function providerName(): string
    {
        return 'Apple';
    }
}
