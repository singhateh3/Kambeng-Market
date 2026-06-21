<?php

// app/Http/Controllers/Admin/AdminFarmerVerificationController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
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
    }

    /**
     * Get all farmers (with filters)
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::where('role', 'farmer')
            ->with('farmerProfile')
            ->when($request->status, function ($query, $status) {
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
    }

    /**
     * Get a specific farmer for verification
     */
    public function show(User $farmer): JsonResponse
    {
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
    }

    /**
     * Approve a farmer
     */
    public function approve(Request $request, User $farmer): JsonResponse
    {
        if (!$farmer->isFarmer()) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a farmer',
            ], 422);
        }

        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        // Approve the farmer
        $farmer->approveVerification();

        // Add verification notes
        if ($request->notes) {
            $farmer->farmerProfile()->update([
                'verification_notes' => $request->notes,
            ]);
        }

        // TODO: Send notification to farmer

        return response()->json([
            'success' => true,
            'message' => 'Farmer approved successfully',
            'data' => new UserResource($farmer->load('farmerProfile')),
        ]);
    }

    /**
     * Reject a farmer
     */
    public function reject(Request $request, User $farmer): JsonResponse
    {
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
        $farmer->rejectVerification($request->reason);

        // TODO: Send notification to farmer with rejection reason

        return response()->json([
            'success' => true,
            'message' => 'Farmer rejected',
            'data' => new UserResource($farmer->load('farmerProfile')),
        ]);
    }

    /**
     * Bulk approve farmers
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $request->validate([
            'farmer_ids' => 'required|array',
            'farmer_ids.*' => 'exists:users,id',
        ]);

        $farmers = User::whereIn('id', $request->farmer_ids)
            ->where('role', 'farmer')
            ->where('verification_status', 'pending')
            ->get();

        foreach ($farmers as $farmer) {
            $farmer->approveVerification();
        }

        return response()->json([
            'success' => true,
            'message' => $farmers->count() . ' farmers approved successfully',
        ]);
    }

    /**
     * Upload verification document
     */
    public function uploadDocument(Request $request, User $farmer): JsonResponse
    {
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
    }

    /**
     * Get verification statistics
     */
    public function statistics(): JsonResponse
    {
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
    }
}