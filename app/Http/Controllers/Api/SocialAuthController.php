<?php

// app/Http/Controllers/Api/SocialAuthController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Auth\AppleTokenVerifier;
use App\Services\Auth\GoogleTokenVerifier;
use App\Services\Auth\JwtProviderVerifier;
use App\Services\Auth\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/auth/google, POST /api/auth/apple — the frontend obtains a
 * signed identity token directly from the provider's own SDK (Google
 * Identity Services / Apple JS) and posts ONLY that token here. This
 * backend independently verifies its signature/issuer/audience/expiry
 * against the provider's own published keys (JwtProviderVerifier) before
 * trusting anything in it — the client never gets to just assert "I am
 * this email" directly. Issues the same Sanctum token, in the same
 * response shape, as AuthController::login()/register(), so the frontend
 * needs no separate handling path for social vs password auth.
 */
class SocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthService $socialAuth,
    ) {
    }

    public function google(Request $request, GoogleTokenVerifier $verifier): JsonResponse
    {
        return $this->handle($request, $verifier, 'google');
    }

    public function apple(Request $request, AppleTokenVerifier $verifier): JsonResponse
    {
        return $this->handle($request, $verifier, 'apple');
    }

    private function handle(Request $request, JwtProviderVerifier $verifier, string $provider): JsonResponse
    {
        // `name` is optional and only ever meaningful for Apple: its
        // identity token never carries a name claim (unlike Google's,
        // which does), and Apple's JS SDK hands the given/family name back
        // as a *separate* object in its authorization response, present
        // ONLY on a user's very first authorization for this app — never
        // recoverable afterward. It carries no authority on its own (only
        // the verified token's sub/email do) — see SocialAuthService,
        // which only ever applies it when actually creating a brand-new
        // user, never to overwrite an existing account's name.
        $request->validate([
            'id_token' => 'required|string',
            'name' => 'nullable|array',
            'name.firstName' => 'nullable|string|max:255',
            'name.lastName' => 'nullable|string|max:255',
        ]);

        try {
            $identity = $verifier->verify($request->input('id_token'));
        } catch (Throwable $e) {
            Log::warning("{$provider} sign-in token verification failed: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Could not verify identity token.',
            ], 401);
        }

        $firstLoginName = trim(implode(' ', array_filter([
            $request->input('name.firstName'),
            $request->input('name.lastName'),
        ]))) ?: null;

        try {
            $user = $this->socialAuth->findOrCreateUser($provider, $identity, $firstLoginName);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        }

        // Same single-active-token model as AuthController::login().
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'data' => [
                'user' => new UserResource($user->load('farmerProfile')),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }
}
