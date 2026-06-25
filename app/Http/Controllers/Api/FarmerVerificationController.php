<?php

// app/Http/Controllers/Api/FarmerVerificationController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FarmerVerificationController extends Controller
{
    /**
     * Request verification for a farmer
     */
    public function requestVerification(Request $request): JsonResponse
    {
        try {
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

            // Update user verification status
            $user->update([
                'verification_status' => 'pending',
                'verification_requested_at' => now(),
            ]);

            // Send notification to all admins
            try {
                $notificationService = app(NotificationService::class);
                
                // Get all admin users
                $admins = User::where('role', 'admin')->get();
                
                foreach ($admins as $admin) {
                    $notificationService->farmerVerificationRequest($user, $admin);
                }
            } catch (\Exception $e) {
                \Log::error('Error sending verification notification to admins: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Verification requested successfully',
                'data' => [
                    'status' => $user->verification_status,
                    'requested_at' => $user->verification_requested_at,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error requesting verification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error requesting verification: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get verification status
     */
    public function status(Request $request): JsonResponse
    {
        try {
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
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching verification status: ' . $e->getMessage(),
            ], 500);
        }
    }
}