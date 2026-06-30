<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginUserRequest;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
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
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $validated = $request->validated();

            // Handle avatar upload
            if ($request->hasFile('avatar')) {
                $avatarPath = $request->file('avatar')->store('avatars', 'public');
                $validated['avatar'] = $avatarPath;

                // Delete old avatar if exists
                if ($user->avatar) {
                    Storage::disk('public')->delete($user->avatar);
                }
            }

            // Update user data
            $user->update($validated);

            // Update farmer profile if exists
            if ($user->isFarmer() && ($request->has('farm_name') || $request->has('farm_location') || $request->has('bio'))) {
                $user->farmerProfile()->update([
                    'farm_name' => $validated['farm_name'] ?? $user->farmerProfile->farm_name,
                    'farm_location' => $validated['farm_location'] ?? $user->farmerProfile->farm_location,
                    'bio' => $validated['bio'] ?? $user->farmerProfile->bio,
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
