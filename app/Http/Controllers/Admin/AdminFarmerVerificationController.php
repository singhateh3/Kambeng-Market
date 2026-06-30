<?php

// app/Http/Controllers/Admin/AdminFarmerVerificationController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class AdminFarmerVerificationController extends Controller
{
    /**
     * Get all farmers pending verification
     */
    public function pending(): JsonResponse
    {
        try {
            $farmers = User::where('role', 'farmer')
                ->where('verification_status', 'pending')
                ->with('farmerProfile')
                ->latest('verification_requested_at')
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
        } catch (\Exception $e) {
            \Log::error('Error fetching pending farmers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching pending farmers: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all farmers (with filters)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = User::where('role', 'farmer')
                ->with('farmerProfile')
                ->when($request->status, function ($query, $status) {
                    if ($status === '') {
                        return $query;
                    }
                    return $query->where('verification_status', $status);
                })
                ->when($request->search, function ($query, $search) {
                    return $query->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhereHas('farmerProfile', function ($q) use ($search) {
                                $q->where('farm_name', 'like', "%{$search}%")
                                    ->orWhere('farm_location', 'like', "%{$search}%");
                            });
                    });
                });

            $farmers = $query->latest()->paginate($request->per_page ?? 20);

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
        } catch (\Exception $e) {
            \Log::error('Error fetching farmers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching farmers: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get count of pending farmer verifications
     */
    public function pendingVerificationsCount(): JsonResponse
    {
        try {
            $count = User::where('role', 'farmer')
                ->where('verification_status', 'pending')
                ->count();

            return response()->json([
                'success' => true,
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching pending verifications count: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching pending verifications count',
            ], 500);
        }
    }

    /**
     * Get a specific farmer for verification
     */
    public function show(User $farmer): JsonResponse
    {
        try {
            if (!$farmer->isFarmer()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not a farmer',
                ], 422);
            }

            $farmer->load('farmerProfile');

            return response()->json([
                'success' => true,
                'data' => new UserResource($farmer),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching farmer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching farmer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve a farmer
     */
    public function approve(Request $request, User $farmer): JsonResponse
    {
        try {
            if (!$farmer->isFarmer()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not a farmer',
                ], 422);
            }

            $request->validate([
                'notes' => 'nullable|string|max:500',
            ]);

            // Approve the farmer - update both user and farmer profile
            $farmer->update([
                'verified_at' => now(),
                'verification_status' => 'approved',
            ]);

            // Add verification notes and update farmer profile
            if ($farmer->farmerProfile) {
                $farmer->farmerProfile->update([
                    'id_verified' => true,
                    'verification_notes' => $request->notes,
                ]);
            }

            // Send notification to the farmer AND admins
            try {
                $notificationService = app(NotificationService::class);
                $notificationService->farmerVerified($farmer);
            } catch (\Exception $e) {
                \Log::error('Error sending verification approval notification: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Farmer approved successfully',
                'data' => new UserResource($farmer->load('farmerProfile')),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error approving farmer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error approving farmer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject a farmer
     */
    public function reject(Request $request, User $farmer): JsonResponse
    {
        try {
            if (!$farmer->isFarmer()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not a farmer',
                ], 422);
            }

            $request->validate([
                'reason' => 'required|string|max:500',
            ]);

            // Reject the farmer
            $farmer->update([
                'verification_status' => 'rejected',
            ]);

            if ($farmer->farmerProfile) {
                $farmer->farmerProfile->update([
                    'id_verified' => false,
                    'rejection_reason' => $request->reason,
                    'rejected_at' => now(),
                ]);
            }

            // Send notification to the farmer with rejection reason AND admins
            try {
                $notificationService = app(NotificationService::class);
                $notificationService->farmerRejected($farmer, $request->reason);
            } catch (\Exception $e) {
                \Log::error('Error sending verification rejection notification: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Farmer rejected',
                'data' => new UserResource($farmer->load('farmerProfile')),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error rejecting farmer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error rejecting farmer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk approve farmers
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'farmer_ids' => 'required|array',
                'farmer_ids.*' => 'exists:users,id',
            ]);

            $farmers = User::whereIn('id', $request->farmer_ids)
                ->where('role', 'farmer')
                ->where('verification_status', 'pending')
                ->get();

            foreach ($farmers as $farmer) {
                $farmer->update([
                    'verified_at' => now(),
                    'verification_status' => 'approved',
                ]);

                if ($farmer->farmerProfile) {
                    $farmer->farmerProfile->update([
                        'id_verified' => true,
                    ]);
                }

                // Send notification to each farmer AND admins
                try {
                    $notificationService = app(NotificationService::class);
                    $notificationService->farmerVerified($farmer);
                } catch (\Exception $e) {
                    \Log::error('Error sending bulk verification notification: ' . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => $farmers->count() . ' farmers approved successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error bulk approving farmers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error bulk approving farmers: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload verification document
     */
    public function uploadDocument(Request $request, User $farmer): JsonResponse
    {
        try {
            if (!$farmer->isFarmer()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not a farmer',
                ], 422);
            }

            $request->validate([
                'document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
                'type' => 'required|in:verification_document,business_license,id_document',
            ]);

            $file = $request->file('document');
            $path = $file->store('farmer_documents/' . $farmer->id, 'public');
            $url = Storage::url($path);

            // Update farmer profile with document path
            $farmer->farmerProfile()->update([
                $request->type => $url,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Document uploaded successfully',
                'data' => [
                    'url' => $url,
                    'path' => $path,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error uploading document: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error uploading document: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get verification statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total_farmers' => User::where('role', 'farmer')->count(),
                'pending' => User::where('role', 'farmer')
                    ->where('verification_status', 'pending')
                    ->count(),
                'approved' => User::where('role', 'farmer')
                    ->where('verification_status', 'approved')
                    ->count(),
                'rejected' => User::where('role', 'farmer')
                    ->where('verification_status', 'rejected')
                    ->count(),
                'not_submitted' => User::where('role', 'farmer')
                    ->whereNull('verification_status')
                    ->orWhere('verification_status', '')
                    ->count(),
                'recent_requests' => User::where('role', 'farmer')
                    ->whereNotNull('verification_requested_at')
                    ->with('farmerProfile')
                    ->latest('verification_requested_at')
                    ->limit(5)
                    ->get(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching statistics: ' . $e->getMessage(),
            ], 500);
        }
    }
}
