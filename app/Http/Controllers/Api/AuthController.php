<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginUserRequest;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\CloudinaryService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(RegisterUserRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Hash password
            $validated['password'] = Hash::make($validated['password']);

            // Create user
            $user = User::create($validated);

            // Create farmer profile if role is farmer
            if ($validated['role'] === 'farmer') {
                $user->farmerProfile()->create([
                    'farm_name' => $validated['farm_name'] ?? null,
                    'farm_location' => $validated['farm_location'] ?? null,
                    'bio' => $validated['bio'] ?? null,
                ]);
            }

            // Generate token
            $token = $user->createToken('auth_token')->plainTextToken;

            // Send notification to admins about new user registration
            try {
                $notificationService = app(NotificationService::class);
                $notificationService->userRegistered($user);
            } catch (\Exception $e) {
                \Log::error('Error sending user registration notification: ' . $e->getMessage());
                // Don't fail the registration if notification fails
            }

            return response()->json([
                'message' => 'Registration successful',
                'data' => [
                    'user' => new UserResource($user->load('farmerProfile')),
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Error registering user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error registering user: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Login user
     */
    public function login(LoginUserRequest $request): JsonResponse
    {
        try {
            // A social-only account (Google/Apple, no password ever set)
            // safely fails Hash::check() against a null hash rather than
            // crashing — Laravel's hasher explicitly guards that — but a
            // specific message here is a better experience than the
            // generic "incorrect credentials" for someone who simply
            // hasn't set a password yet.
            $socialOnlyUser = User::where('email', $request->email)->whereNull('password')->first();
            if ($socialOnlyUser) {
                throw ValidationException::withMessages([
                    'email' => ['This account signs in with Google or Apple. Use that instead, or set a password from your profile first.'],
                ]);
            }

            // Attempt to authenticate
            if (!auth()->attempt($request->credentials(), $request->shouldRemember())) {
                throw ValidationException::withMessages([
                    'email' => ['The provided credentials are incorrect.'],
                ]);
            }

            // Get user
            $user = User::where('email', $request->email)->firstOrFail();

            // Revoke existing tokens (optional - good for security)
            $user->tokens()->delete();

            // Create new token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Login successful',
                'data' => [
                    'user' => new UserResource($user->load('farmerProfile')),
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error logging in: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error logging in: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Revoke the current access token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Logged out successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error logging out: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error logging out: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request): UserResource
    {
        return new UserResource(
            $request->user()->load('farmerProfile')
        );
    }

    /**
     * Update user profile
     */
    public function updateProfile(UpdateProfileRequest $request, CloudinaryService $cloudinaryService): JsonResponse
    {
        try {
            $user = $request->user();
            $validated = $request->validated();
            unset($validated['remove_avatar']); // control flag, not a users column

            if ($request->hasFile('avatar')) {
                try {
                    $new = $this->storeAvatar($request->file('avatar'), $cloudinaryService);
                } catch (\Exception $e) {
                    \Log::error('Avatar upload failed: ' . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Could not upload your photo right now. Please try again.',
                    ], 502);
                }

                $this->deleteAvatar($user->avatar, $user->avatar_public_id, $cloudinaryService);
                $validated['avatar'] = $new['secure_url'];
                $validated['avatar_public_id'] = $new['public_id'];
            } elseif ($request->boolean('remove_avatar')) {
                $this->deleteAvatar($user->avatar, $user->avatar_public_id, $cloudinaryService);
                $validated['avatar'] = null;
                $validated['avatar_public_id'] = null;
            }

            // A role change only ever arrives here from the post-Google-
            // sign-in profile completion step (CompleteProfile.jsx) — Google
            // always creates buyers (see SocialAuthService), so this lets
            // one who's actually a farmer say so. Restricted to buyer ->
            // farmer only, mirroring what AuthController::register() already
            // lets anyone choose freely at signup; no other transition
            // (farmer -> buyer, anything -> admin) is accepted through this
            // endpoint — that stays AdminUserController::updateRole's job.
            if (array_key_exists('role', $validated) && $validated['role'] !== $user->role) {
                if ($user->role !== 'buyer' || $validated['role'] !== 'farmer') {
                    return response()->json([
                        'success' => false,
                        'message' => 'This account cannot change role this way.',
                    ], 422);
                }
            }

            // Update user data
            $user->update($validated);

            // Create/update the farmer profile. Covers both an existing
            // farmer editing their farm details AND a buyer who just became
            // a farmer above — the latter has no farmer_profiles row yet, so
            // a plain ->update() (the old behavior) would silently affect
            // zero rows and leave the account farmer-flagged but profile-less.
            if ($user->isFarmer() && ($request->has('farm_name') || $request->has('farm_location') || $request->has('bio') || $request->has('role'))) {
                $user->farmerProfile()->updateOrCreate([], [
                    'farm_name' => $validated['farm_name'] ?? optional($user->farmerProfile)->farm_name,
                    'farm_location' => $validated['farm_location'] ?? optional($user->farmerProfile)->farm_location,
                    'bio' => $validated['bio'] ?? optional($user->farmerProfile)->bio,
                ]);
            }

            // Update password if provided
            if ($request->filled('new_password')) {
                $user->update([
                    'password' => Hash::make($validated['new_password']),
                ]);
            }

            return response()->json([
                'message' => 'Profile updated successfully',
                'data' => new UserResource($user->fresh()->load('farmerProfile')),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error updating profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating profile: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @return array{secure_url: string, public_id: ?string}
     *
     * @throws \Exception on a Cloudinary upload failure — callers must
     *         catch this; never let it surface as a raw 500 with an
     *         internal exception message.
     */
    private function storeAvatar($file, CloudinaryService $cloudinaryService): array
    {
        if ($cloudinaryService->isConfigured()) {
            return $cloudinaryService->upload($file, 'avatars');
        }

        // Same local-disk fallback updateProfile() always used before
        // Cloudinary was wired up here — keeps this endpoint working
        // wherever CLOUDINARY_URL isn't set (local dev, CI; see
        // phpunit.xml). public_id stays null: nothing to delete from
        // Cloudinary for an avatar that was never uploaded there.
        return [
            'secure_url' => $file->store('avatars', 'public'),
            'public_id' => null,
        ];
    }

    /**
     * Delete a previous avatar, however it was stored. A Cloudinary
     * public_id takes priority when present; otherwise, only a legacy
     * local-disk relative path (never a bare URL with no public_id — see
     * the migration for why one might exist without the other) is removed
     * from the public disk. Failures are logged, never thrown — losing
     * track of one old asset must not block saving the new avatar.
     */
    private function deleteAvatar(?string $avatar, ?string $avatarPublicId, CloudinaryService $cloudinaryService): void
    {
        if ($avatarPublicId) {
            try {
                $cloudinaryService->delete($avatarPublicId);
            } catch (\Exception $e) {
                \Log::warning('Failed to delete old Cloudinary avatar: ' . $e->getMessage());
            }
        } elseif ($avatar && !str_starts_with($avatar, 'http://') && !str_starts_with($avatar, 'https://')) {
            Storage::disk('public')->delete($avatar);
        }
    }

    /**
     * Send password reset link
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:users,email',
            ]);

            // You would implement your password reset logic here
            // Password::sendResetLink($request->only('email'));

            return response()->json([
                'message' => 'Password reset link sent to your email',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error sending password reset: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error sending password reset: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh token (optional)
     */
    public function refreshToken(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Revoke all tokens and create new one
            $user->tokens()->delete();
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Token refreshed successfully',
                'data' => [
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error refreshing token: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error refreshing token: ' . $e->getMessage(),
            ], 500);
        }
    }
}
