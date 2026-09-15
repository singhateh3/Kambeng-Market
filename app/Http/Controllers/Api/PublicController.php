<?php

// app/Http/Controllers/Api/PublicController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\Review;
use App\Support\DashboardCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PublicController extends Controller
{
    /**
     * Get public statistics for the homepage. Cached — same pattern as
     * AdminDashboardController::statistics() / FarmerProfileController::
     * statistics() (Cache::remember via DashboardCache, same TTL).
     * Unlike those two, this endpoint is fully public/unauthenticated, so
     * it's also rate-limited (see the 'public-statistics' limiter in
     * AppServiceProvider) — previously it had neither, running 4
     * uncached aggregate queries on every anonymous request.
     * Invalidated by the Order/Product/User/Review model events
     * registered in AppServiceProvider::configureDashboardCacheInvalidation().
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = Cache::remember(
                DashboardCache::publicKey(),
                DashboardCache::TTL_SECONDS,
                fn () => $this->computeStatistics()
            );

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

    private function computeStatistics(): array
    {
        return [
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
    }
}
