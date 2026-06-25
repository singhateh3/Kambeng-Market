<?php

// app/Http/Controllers/Admin/AdminUserController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    /**
     * Get all users with filtering
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = User::with('farmerProfile')
                ->when($request->role, function ($query, $role) {
                    return $query->where('role', $role);
                })
                ->when($request->search, function ($query, $search) {
                    return $query->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%")
                          ->orWhere('phone', 'like', "%{$search}%");
                    });
                })
                ->when($request->verified === '1', function ($query) {
                    return $query->whereNotNull('verified_at');
                })
                ->when($request->verified === '0', function ($query) {
                    return $query->whereNull('verified_at');
                });

            $users = $query->latest()->paginate($request->per_page ?? 20);

            return response()->json([
                'success' => true,
                'data' => UserResource::collection($users),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching users: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching users: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific user
     */
    public function show(User $user): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => new UserResource($user->load('farmerProfile')),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching user: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update user role
     */
    public function updateRole(Request $request, User $user): JsonResponse
    {
        try {
            $request->validate([
                'role' => 'required|in:farmer,buyer,admin',
            ]);

            // Prevent admin from changing their own role
            if ($request->user()->id === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot change your own role',
                ], 422);
            }

            $user->update(['role' => $request->role]);

            return response()->json([
                'success' => true,
                'message' => 'User role updated successfully',
                'data' => new UserResource($user),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating role: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify a farmer
     */
    public function verifyFarmer(Request $request, User $user): JsonResponse
    {
        try {
            if (!$user->isFarmer()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not a farmer',
                ], 422);
            }

            if ($user->isVerified()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Farmer is already verified',
                ], 422);
            }

            $user->update([
                'verified_at' => now(),
                'verification_status' => 'approved',
            ]);

            if ($user->farmerProfile) {
                $user->farmerProfile->update([
                    'id_verified' => true,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Farmer verified successfully',
                'data' => new UserResource($user->load('farmerProfile')),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error verifying farmer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle user status (activate/suspend)
     */
    public function toggleStatus(Request $request, User $user): JsonResponse
    {
        try {
            $request->validate([
                'is_active' => 'required|boolean',
            ]);

            // Prevent admin from toggling their own status
            if ($request->user()->id === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot change your own status',
                ], 422);
            }

            $user->update(['is_active' => $request->is_active]);

            return response()->json([
                'success' => true,
                'message' => $request->is_active ? 'User activated successfully' : 'User suspended successfully',
                'data' => new UserResource($user),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error toggling user status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a user
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        try {
            // Prevent admin from deleting themselves
            if ($request->user()->id === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account',
                ], 422);
            }

            // Delete related data
            if ($user->farmerProfile) {
                $user->farmerProfile->delete();
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting user: ' . $e->getMessage(),
            ], 500);
        }
    }
}