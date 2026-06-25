<?php

// app/Http/Controllers/Api/PublicController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\JsonResponse;

class PublicController extends Controller
{
    /**
     * Get public statistics for the homepage
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'products' => [
                    'active' => Product::where('status', 'active')
                        ->where('expiry_date', '>=', now())
                        ->count(),
                ],
                'users' => [
                    'farmers' => User::where('role', 'farmer')->count(),
                ],
                'orders' => [
                    'total' => Order::count(),
                ],
                'reviews' => [
                    'average_rating' => round(Review::avg('rating') ?? 0, 1),
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching public statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching statistics',
            ], 500);
        }
    }
}
