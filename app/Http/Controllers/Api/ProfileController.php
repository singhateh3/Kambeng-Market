<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    /**
     * Get user profile
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:20',
                'location' => 'nullable|string|max:255',
                'bio' => 'nullable|string|max:500',
                'farm_name' => 'nullable|string|max:255',
                'farm_location' => 'nullable|string|max:255',
                'avatar' => 'nullable|image|mimes:jpeg,png,gif,webp|max:5120',
            ]);

            // Handle avatar upload
            if ($request->hasFile('avatar')) {
                // Delete old avatar if exists
                if ($user->avatar) {
                    Storage::disk('public')->delete($user->avatar);
                }
                $path = $request->file('avatar')->store('avatars', 'public');
                $validated['avatar'] = $path;
            }

            // Update user
            $user->update($validated);

            // Update farmer profile if exists
            if ($user->isFarmer() && $user->farmerProfile) {
                $user->farmerProfile->update([
                    'bio' => $validated['bio'] ?? $user->farmerProfile->bio,
                    'farm_name' => $validated['farm_name'] ?? $user->farmerProfile->farm_name,
                    'farm_location' => $validated['farm_location'] ?? $user->farmerProfile->farm_location,
                ]);
            }

            // Refresh and load relationships
            $user->refresh();
            $user->load('farmerProfile');

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $user, // Return the updated user in 'data'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error updating profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating profile: ' . $e->getMessage(),
            ], 500);
        }
    }
}
