<?php


namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Get admin dashboard statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'users' => [
                'total' => User::count(),
                'farmers' => User::where('role', 'farmer')->count(),
                'buyers' => User::where('role', 'buyer')->count(),
                'admins' => User::where('role', 'admin')->count(),
                'verified_farmers' => User::where('role', 'farmer')
                    ->whereNotNull('verified_at')
                    ->count(),
                'unverified_farmers' => User::where('role', 'farmer')
                    ->whereNull('verified_at')
                    ->count(),
                'new_users_today' => User::whereDate('created_at', today())->count(),
                'new_users_this_week' => User::whereBetween('created_at', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ])->count(),
            ],
            'products' => [
                'total' => Product::count(),
                'active' => Product::where('status', 'active')->count(),
                'sold' => Product::where('status', 'sold')->count(),
                'expiring_soon' => Product::where('status', 'active')
                    ->whereBetween('expiry_date', [now(), now()->addDays(7)])
                    ->count(),
                'expired' => Product::where('expiry_date', '<', now())->count(),
                'top_categories' => Product::select('category', DB::raw('count(*) as count'))
                    ->groupBy('category')
                    ->orderBy('count', 'desc')
                    ->limit(5)
                    ->get(),
            ],
            'orders' => [
                'total' => Order::count(),
                'pending' => Order::where('status', 'pending')->count(),
                'confirmed' => Order::where('status', 'confirmed')->count(),
                'shipped' => Order::where('status', 'shipped')->count(),
                'delivered' => Order::where('status', 'delivered')->count(),
                'cancelled' => Order::where('status', 'cancelled')->count(),
                'total_revenue' => Order::where('status', 'delivered')->sum('total_price'),
                'average_order_value' => Order::where('status', 'delivered')->avg('total_price'),
                'orders_today' => Order::whereDate('created_at', today())->count(),
                'orders_this_week' => Order::whereBetween('created_at', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ])->count(),
            ],
            'reviews' => [
                'total' => Review::count(),
                'average_rating' => round(Review::avg('rating') ?? 0, 1),
                'five_star' => Review::where('rating', 5)->count(),
                'four_star' => Review::where('rating', 4)->count(),
                'three_star' => Review::where('rating', 3)->count(),
                'two_star' => Review::where('rating', 2)->count(),
                'one_star' => Review::where('rating', 1)->count(),
            ],
            'recent_activity' => [
                'recent_users' => User::latest()->limit(5)->get(['id', 'name', 'email', 'role', 'created_at']),
                'recent_orders' => Order::with(['buyer', 'product'])
                    ->latest()
                    ->limit(5)
                    ->get(),
                'recent_products' => Product::with('farmer')
                    ->latest()
                    ->limit(5)
                    ->get(),
                'recent_reviews' => Review::with(['user', 'order.product'])
                    ->latest()
                    ->limit(5)
                    ->get(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get chart data for dashboard
     */
    public function chartData(): JsonResponse
    {
        // Get last 7 days of orders
        $dailyOrders = Order::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as count'),
            DB::raw('sum(total_price) as revenue')
        )
        ->where('created_at', '>=', now()->subDays(7))
        ->groupBy('date')
        ->orderBy('date')
        ->get();

        // Get monthly revenue
        $monthlyRevenue = Order::select(
            DB::raw('MONTH(created_at) as month'),
            DB::raw('YEAR(created_at) as year'),
            DB::raw('sum(total_price) as revenue')
        )
        ->where('status', 'delivered')
        ->where('created_at', '>=', now()->subMonths(6))
        ->groupBy('year', 'month')
        ->orderBy('year')
        ->orderBy('month')
        ->get();

        // User growth over time
        $userGrowth = User::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as count')
        )
        ->where('created_at', '>=', now()->subDays(30))
        ->groupBy('date')
        ->orderBy('date')
        ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'daily_orders' => $dailyOrders,
                'monthly_revenue' => $monthlyRevenue,
                'user_growth' => $userGrowth,
            ],
        ]);
    }
}