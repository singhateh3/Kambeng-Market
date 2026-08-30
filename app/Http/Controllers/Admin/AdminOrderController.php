<?php


namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdminOrderController extends Controller
{
    /**
     * Get all orders with filtering
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['buyer', 'product', 'product.farmer'])
            ->when($request->status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->buyer_id, function ($query, $buyerId) {
                return $query->where('buyer_id', $buyerId);
            })
            ->when($request->farmer_id, function ($query, $farmerId) {
                return $query->whereHas('product', function ($q) use ($farmerId) {
                    $q->where('farmer_id', $farmerId);
                });
            })
            ->when($request->date_from, function ($query, $dateFrom) {
                return $query->whereDate('created_at', '>=', $dateFrom);
            })
            ->when($request->date_to, function ($query, $dateTo) {
                return $query->whereDate('created_at', '<=', $dateTo);
            })
            ->when($request->min_price, function ($query, $minPrice) {
                return $query->where('total_price', '>=', $minPrice);
            })
            ->when($request->max_price, function ($query, $maxPrice) {
                return $query->where('total_price', '<=', $maxPrice);
            });

        $orders = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => OrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Get a specific order
     */
    public function show(Order $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new OrderResource($order->load(['buyer', 'product', 'product.farmer', 'review'])),
        ]);
    }

    /**
     * Update order status
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:pending,confirmed,shipped,delivered,cancelled',
        ]);

        // Same COD-at-delivery / cancelled derivation as the farmer-facing
        // OrderController::updateStatus() — see Order::derivedPaymentStatus().
        $updates = ['status' => $request->status];
        if ($derivedPaymentStatus = $order->derivedPaymentStatus($request->status)) {
            $updates['payment_status'] = $derivedPaymentStatus;
        }
        $order->update($updates);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'data' => new OrderResource($order->load(['buyer', 'product'])),
        ]);
    }

    /**
     * Delete an order
     */
    public function destroy(Order $order): JsonResponse
    {
        $order->delete();

        return response()->json([
            'success' => true,
            'message' => 'Order deleted successfully',
        ]);
    }
}