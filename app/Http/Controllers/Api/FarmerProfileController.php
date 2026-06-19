<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FarmerProfile\UpdateFarmerProfileRequest;
use App\Http\Resources\FarmerProfileResource;
use App\Models\FarmerProfile;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FarmerProfileController extends Controller
{
    /**
     * Display the farmer profile.
     */
    public function show(Request $request): JsonResponse|FarmerProfileResource
    {
        $profile = $request->user()->farmerProfile;
        
        if (!$profile) {
            return response()->json([
                'message' => 'Farmer profile not found',
            ], 404);
        }

        $profile->load(['user']);
        
        return new FarmerProfileResource($profile);
    }

    /**
     * Update the farmer profile.
     */
    public function update(UpdateFarmerProfileRequest $request): JsonResponse
    {
        $profile = $request->user()->farmerProfile;
        
        if (!$profile) {
            return response()->json([
                'message' => 'Farmer profile not found',
            ], 404);
        }

        $validated = $request->validated();
        $profile->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'data' => new FarmerProfileResource($profile->load('user')),
        ]);
    }

    /**
     * Get public farmer profile (for buyers).
     */
    public function publicShow(int $userId): FarmerProfileResource
    {
        // Explicit operator to avoid any ambiguous parsing issues
        $profile = FarmerProfile::where('user_id', '=', $userId)
            ->with(['user', 'products' => function ($query) {
                $query->active()->limit(10);
            }])
            ->firstOrFail();

        return new FarmerProfileResource($profile);
    }

    /**
     * Get farmer statistics.
     */
    // app/Http/Controllers/Api/FarmerProfileController.php

/**
 * Get farmer statistics.
 */
public function statistics(Request $request): JsonResponse
{
    $profile = $request->user()->farmerProfile;
    
    if (!$profile) {
        return response()->json([
            'message' => 'Farmer profile not found',
        ], 404);
    }

    // Get orders through products
    $orders = Order::whereHas('product', function ($query) use ($profile) {
        $query->where('farmer_id', $profile->user_id);
    });

    $stats = [
        'total_products' => $profile->products()->count(),
        'active_products' => $profile->products()->active()->count(),
        'sold_products' => $profile->products()->where('status', 'sold')->count(),
        'expiring_soon' => $profile->products()->where('status', 'active')
            ->whereBetween('expiry_date', [now(), now()->addDays(7)])->count(),
        'total_orders' => $orders->count(),
        'pending_orders' => (clone $orders)->where('status', 'pending')->count(),
        'confirmed_orders' => (clone $orders)->where('status', 'confirmed')->count(),
        'shipped_orders' => (clone $orders)->where('status', 'shipped')->count(),
        'delivered_orders' => (clone $orders)->where('status', 'delivered')->count(),
        'cancelled_orders' => (clone $orders)->where('status', 'cancelled')->count(),
        'total_revenue' => (clone $orders)->where('status', 'delivered')->sum('total_price'),
        'average_rating' => round($profile->getFarmerAverageRating() ?? 0, 1),
        'profile_completion' => $profile->completion_percentage,
    ];

    return response()->json(['data' => $stats]);
}
}