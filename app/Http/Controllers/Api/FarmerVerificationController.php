<?php

// app/Http/Controllers/Api/FarmerVerificationController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FarmerVerificationController extends Controller
{
    /**
     * Request verification
     */
    public function requestVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user->isFarmer()) {
            return response()->json([
                'success' => false,
                'message' => 'Only farmers can request verification',
            ], 422);
        }

        if ($user->isVerified()) {
            return response()->json([
                'success' => false,
                'message' => 'You are already verified',
            ], 422);
        }

        if ($user->isVerificationPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Verification already pending',
            ], 422);
        }

        $user->requestVerification();

        return response()->json([
            'success' => true,
            'message' => 'Verification requested successfully',
            'data' => [
                'status' => $user->verification_status,
                'requested_at' => $user->verification_requested_at,
            ],
        ]);
    }

    /**
     * Get verification status
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        
        return response()->json([
            'success' => true,
            'data' => [
                'status' => $user->verification_status,
                'status_label' => $user->verification_status_label,
                'is_verified' => $user->isVerified(),
                'requested_at' => $user->verification_requested_at,
                'verified_at' => $user->verified_at,
            ],
        ]);
    }
}