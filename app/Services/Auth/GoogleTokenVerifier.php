<?php

// app/Services/Auth/GoogleTokenVerifier.php

namespace App\Services\Auth;

/**
 * Verifies a Google Identity Services ID token against Google's published
 * JWKS. See JwtProviderVerifier for the actual signature/issuer/audience
 * checks — this class only supplies Google's specific values.
 */
class GoogleTokenVerifier extends JwtProviderVerifier
{
    protected function jwksUrl(): string
    {
        return 'https://www.googleapis.com/oauth2/v3/certs';
    }

    protected function jwksCacheKey(): string
    {
        return 'auth:google:jwks';
    }

    protected function expectedIssuers(): array
    {
        // Google's docs document both forms as valid issuer values.
        return ['accounts.google.com', 'https://accounts.google.com'];
    }

    protected function expectedAudience(): ?string
    {
        return config('services.google.client_id');
    }

    protected function providerName(): string
    {
        return 'Google';
    }
}
