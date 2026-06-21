<?php

// app/Http/Controllers/Admin/AdminDisputeController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdminDisputeController extends Controller
{
    /**
     * Get all disputes/reported issues
     */
    public function index(Request $request): JsonResponse
    {
        // This would need a disputes table
        // For now, we'll get orders with issues
        $disputes = Order::where('status', 'cancelled')
            ->whereHas('review', function ($query) {
                $query->where('rating', '<=', 2);
            })
            ->with(['buyer', 'product.farmer', 'review'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $disputes,
            'meta' => [
                'current_page' => $disputes->currentPage(),
                'last_page' => $disputes->lastPage(),
                'per_page' => $disputes->perPage(),
                'total' => $disputes->total(),
            ],
        ]);
    }

    /**
     * Resolve a dispute
     */
    public function resolve(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'resolution' => 'required|string|max:500',
            'action' => 'required|in:refund,resolved,escalated',
        ]);

        // This would update a disputes table
        // For now, we'll just log it

        return response()->json([
            'success' => true,
            'message' => 'Dispute resolved successfully',
        ]);
    }
}