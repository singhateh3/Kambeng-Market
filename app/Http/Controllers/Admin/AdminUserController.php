<?php

// app/Http/Controllers/Admin/AdminUserController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    /**
     * Get all users with filtering
     */
    public function index(Request $request): JsonResponse
    {
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
            ->when($request->verified, function ($query) {
                return $query->whereNotNull('verified_at');
            })
            ->when($request->unverified, function ($query) {
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
    }

    /**
     * Get a specific user
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new UserResource($user->load('farmerProfile')),
        ]);
    }

    /**
     * Update user role
     */
    public function updateRole(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'role' => 'required|in:farmer,buyer,admin',
        ]);

        $user->update(['role' => $request->role]);

        return response()->json([
            'success' => true,
            'message' => 'User role updated successfully',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Verify a farmer
     */
    public function verifyFarmer(Request $request, User $user): JsonResponse
    {
        if (!$user->isFarmer()) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a farmer',
            ], 422);
        }

        $user->update([
            'verified_at' => now(),
        ]);

        $user->farmerProfile()->update([
            'id_verified' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Farmer verified successfully',
            'data' => new UserResource($user->load('farmerProfile')),
        ]);
    }

    /**
     * Suspend/activate a user
     */
    public function toggleStatus(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        // You would need to add an 'is_active' column to users table
        // $user->update(['is_active' => $request->is_active]);

        return response()->json([
            'success' => true,
            'message' => $request->is_active ? 'User activated' : 'User suspended',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Delete a user
     */
    public function destroy(User $user): JsonResponse
    {
        // Delete related data
        if ($user->farmerProfile) {
            $user->farmerProfile->delete();
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Get farmers pending verification
     */
    public function pendingVerification(): JsonResponse
    {
        $farmers = User::where('role', 'farmer')
            ->whereNull('verified_at')
            ->with('farmerProfile')
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($farmers),
            'meta' => [
                'current_page' => $farmers->currentPage(),
                'last_page' => $farmers->lastPage(),
                'per_page' => $farmers->perPage(),
                'total' => $farmers->total(),
            ],
        ]);
    }
}