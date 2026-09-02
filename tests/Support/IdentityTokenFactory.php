<?php

// tests/Support/IdentityTokenFactory.php
//
// Generates a real, throwaway RSA keypair and uses it to sign a real RS256
// JWT plus a matching JWKS document — so tests exercise
// JwtProviderVerifier's ACTUAL signature/issuer/audience verification
// logic against Http::fake()'d JWKS endpoints, rather than mocking the
// verifier away and testing nothing about the crypto. No real Google/
// Apple credentials are used or required anywhere in this.

namespace Tests\Support;

use Firebase\JWT\JWT;

class IdentityTokenFactory
{
    private static ?array $keyPair = null;

    /** @return array{0: string, 1: string} [privateKeyPem, publicKeyDetails] */
    private static function keyPair(): array
    {
        if (self::$keyPair !== null) {
            return self::$keyPair;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $privateKeyPem);
        $details = openssl_pkey_get_details($resource);

        return self::$keyPair = [$privateKeyPem, $details['rsa']];
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function kid(): string
    {
        return 'test-key-1';
    }

    /** The JWKS document an Http::fake() should return for the provider's JWKS URL. */
    public static function jwks(): array
    {
        [, $rsa] = self::keyPair();

        return [
            'keys' => [[
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => self::kid(),
                'n' => self::base64UrlEncode($rsa['n']),
                'e' => self::base64UrlEncode($rsa['e']),
            ]],
        ];
    }

    /** A signed identity token with the given claims merged over sane defaults. */
    public static function signedToken(array $claims = []): string
    {
        [$privateKeyPem] = self::keyPair();

        $payload = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-client-id',
            'sub' => 'provider-subject-123',
            'email' => 'social-user@example.com',
            'email_verified' => true,
            'name' => 'Social User',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $claims);

        return JWT::encode($payload, $privateKeyPem, 'RS256', self::kid());
    }
}
