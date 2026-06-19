<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginUserRequest;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
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
        $validated = $request->validated();

        // Hash password
        $validated['password'] = Hash::make($validated['password']);

        // Create user
        $user = User::create($validated);

        // Create farmer profile if role is farmer
        if ($validated['role'] === 'farmer') {
            $user->farmerProfile()->create([
                'farm_name' => $validated['farm_name'],
                'farm_location' => $validated['farm_location'],
                'bio' => $validated['bio'] ?? null,
            ]);
        }

        // Generate token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'data' => [
                'user' => new UserResource($user->load('farmerProfile')),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    /**
     * Login user
     */
    public function login(LoginUserRequest $request): JsonResponse
    {
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
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        // Revoke the current access token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
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
        if ($user->isFarmer() && ($request->has('farm_name') || $request->has('farm_location'))) {
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
    }

    /**
     * Send password reset link
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        // You would implement your password reset logic here
        // Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'Password reset link sent to your email',
        ]);
    }

    /**
     * Refresh token (optional)
     */
    public function refreshToken(Request $request): JsonResponse
    {
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
    }
}