<?php

// app/Services/Auth/SocialAuthService.php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Find-or-create-or-link a User from an already-verified social identity
 * (see JwtProviderVerifier::verify() for the shape of $identity — this
 * class never re-checks the token itself, it trusts that the caller
 * already verified it). Shared by Google and Apple sign-in.
 *
 * Account-linking rule (explicit product decision, Task 12): a verified
 * provider email that matches an existing account gets linked/reused
 * automatically. An UNVERIFIED provider email is never auto-linked —
 * that would let an attacker "sign in with Google/Apple" using an email
 * neither provider has itself confirmed the requester controls, and take
 * over whoever's existing Kambeng account already uses that address.
 */
class SocialAuthService
{
    /**
     * $firstLoginName is optional, client-supplied display-name text —
     * meaningful only for Apple, whose identity token never carries a
     * name claim (unlike Google's) and whose SDK returns given/family
     * name as a separate object ONLY on a user's very first authorization
     * for this app, never recoverable afterward (see
     * SocialAuthController::handle()). It carries no authority of its
     * own — the verified token's sub/email are what establish identity —
     * so it is used ONLY in the User::create() branch below, when we
     * already know (via the provider_id and email lookups above it) that
     * this is genuinely a brand-new account, never to modify an existing
     * user's name.
     */
    public function findOrCreateUser(string $provider, array $identity, ?string $firstLoginName = null): User
    {
        // Already linked to this exact provider identity — the common
        // path for a returning social-auth user. Checked first so a
        // second sign-in never re-evaluates the email-matching rules
        // below against its own already-linked account.
        $existing = User::where('provider', $provider)
            ->where('provider_id', $identity['sub'])
            ->first();
        if ($existing) {
            return $existing;
        }

        $email = $identity['email'] ?? null;

        if ($email) {
            $byEmail = User::where('email', $email)->first();

            if ($byEmail) {
                if (!$identity['email_verified']) {
                    // Can't safely link (would let an unverified claim
                    // take over someone else's account) and can't safely
                    // create a second row (email is unique) — reject
                    // explicitly instead of either.
                    throw new RuntimeException(
                        'An account with this email already exists. Sign in with your password, or verify this email with the provider before linking.'
                    );
                }

                // First social identity being linked to this account —
                // safe to record. If it already has a different provider
                // linked, leave that link untouched rather than overwrite
                // it; the account is still correctly matched and signed
                // into by email either way, and the composite unique
                // index on (provider, provider_id) means we'd never want
                // to silently reassign an existing link regardless.
                if (!$byEmail->provider) {
                    $byEmail->update(['provider' => $provider, 'provider_id' => $identity['sub']]);
                }

                return $byEmail;
            }
        }

        if (!$email) {
            throw new RuntimeException("{$provider} did not provide an email for this sign-in.");
        }

        return User::create([
            'name' => $identity['name'] ?: $firstLoginName ?: Str::before($email, '@'),
            'email' => $email,
            'password' => null,
            'role' => 'buyer',
            'provider' => $provider,
            'provider_id' => $identity['sub'],
            'email_verified_at' => $identity['email_verified'] ? now() : null,
            'verification_status' => 'pending',
        ]);
    }
}
