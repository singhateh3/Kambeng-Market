<?php

// app/Http/Controllers/Api/OrderController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    /**
     * Get orders for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $query = Order::with(['buyer', 'product', 'product.farmer', 'review']);

            // If user is a farmer, show orders for their products
            if ($user->isFarmer()) {
                $query->whereHas('product', function ($q) use ($user) {
                    $q->where('farmer_id', $user->id);
                });
            }
            // If user is a buyer, show their orders
            else if ($user->isBuyer()) {
                $query->where('buyer_id', $user->id);
            }
            // If user is admin, show all orders
            else if ($user->isAdmin()) {
                // Admin sees all orders - no filter needed
            }

            // Filter by status if provided
            if ($request->status) {
                $query->where('status', $request->status);
            }

            $orders = $query->latest('order_date')->paginate($request->per_page ?? 20);

            return response()->json([
                'success' => true,
                'data' => $orders->items(),
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching orders: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching orders: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a new order
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|numeric|min:0.01',
                'delivery_method' => 'required|in:pickup,farmer_delivery',
                'delivery_deadline' => 'nullable|date|after:today',
                'pickup_date' => 'nullable|date|after_or_equal:today',
                'special_instructions' => 'nullable|string|max:500',
            ]);

            $product = Product::findOrFail($request->product_id);

            // Check if product is available
            if (!$product->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product is not available',
                ], 422);
            }

            // Check if the product belongs to a farmer
            if (!$product->farmer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product does not have a valid farmer',
                ], 422);
            }

            $totalPrice = $product->price * $request->quantity;

            $orderData = [
                'buyer_id' => $request->user()->id,
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'total_price' => $totalPrice,
                'delivery_method' => $request->delivery_method,
                'special_instructions' => $request->special_instructions,
                'status' => 'pending',
                'order_date' => now(),
            ];

            // Set the appropriate date based on delivery method
            if ($request->delivery_method === 'pickup') {
                $orderData['pickup_date'] = $request->pickup_date;
                $orderData['delivery_deadline'] = null;
            } else {
                $orderData['delivery_deadline'] = $request->delivery_deadline;
                $orderData['pickup_date'] = null;
            }

            $order = Order::create($orderData);

            // Load relationships for response
            $order->load(['buyer', 'product', 'product.farmer']);

            // Send notification to farmer AND admins
            try {
                $notificationService = app(NotificationService::class);
                $notificationService->orderPlaced($order->product->farmer, $order);
            } catch (\Exception $e) {
                \Log::error('Error sending order placed notification: ' . $e->getMessage());
                // Don't fail the order if notification fails
            }

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully',
                'data' => $order,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error creating order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific order
     */
    public function show(Order $order): JsonResponse
    {
        try {
            // Check if user is authorized to view this order
            $user = auth()->user();

            // Allow if user is the buyer, or the farmer who owns the product, or admin
            $isBuyer = $user->id === $order->buyer_id;
            $isFarmer = $user->isFarmer() && $order->product->farmer_id === $user->id;

            if (!$isBuyer && !$isFarmer && !$user->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this order',
                ], 403);
            }

            $order->load(['buyer', 'product', 'product.farmer', 'review']);

            return response()->json([
                'success' => true,
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update order status (for farmers)
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        try {
            $request->validate([
                'status' => 'required|in:pending,confirmed,shipped,delivered,cancelled',
            ]);

            // Check if user is authorized
            $user = auth()->user();
            $isFarmer = $user->isFarmer() && $order->product->farmer_id === $user->id;

            if (!$isFarmer && !$user->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the farmer can update order status',
                ], 403);
            }

            // Validate status transition
            $validTransitions = [
                'pending' => ['confirmed', 'cancelled'],
                'confirmed' => ['shipped', 'cancelled'],
                'shipped' => ['delivered', 'cancelled'],
                'delivered' => [],
                'cancelled' => [],
            ];

            $currentStatus = $order->status;
            $newStatus = $request->status;

            if (!in_array($newStatus, $validTransitions[$currentStatus] ?? [])) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot transition from '{$currentStatus}' to '{$newStatus}'",
                ], 422);
            }

            $order->update(['status' => $newStatus]);

            // If order is delivered, update product status to sold
            if ($newStatus === 'delivered') {
                $order->product->update(['status' => 'sold']);
            }

            $order->load(['buyer', 'product', 'product.farmer']);

            // Send notifications based on status change
            try {
                $notificationService = app(NotificationService::class);

                switch ($newStatus) {
                    case 'confirmed':
                        // Notify buyer and admins
                        $notificationService->orderConfirmed($order->buyer, $order);
                        break;
                    case 'shipped':
                        // Notify buyer and admins
                        $notificationService->orderShipped($order->buyer, $order);
                        break;
                    case 'delivered':
                        // Notify buyer and admins
                        $notificationService->orderDelivered($order->buyer, $order);
                        break;
                    case 'cancelled':
                        // Notify both buyer and farmer, and admins
                        $notificationService->orderCancelled($order->buyer, $order, 'buyer');
                        $notificationService->orderCancelled($order->product->farmer, $order, 'farmer');
                        break;
                }
            } catch (\Exception $e) {
                \Log::error('Error sending order status notification: ' . $e->getMessage());
                // Don't fail the status update if notification fails
            }

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'data' => $order,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error updating order status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating order status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel an order (buyer can cancel pending orders)
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        try {
            $user = auth()->user();
            $isBuyer = $user->id === $order->buyer_id;
            $isFarmer = $user->isFarmer() && $order->product->farmer_id === $user->id;

            if (!$isBuyer && !$isFarmer && !$user->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to cancel this order',
                ], 403);
            }

            // Only allow cancellation for pending or confirmed orders
            if (!in_array($order->status, ['pending', 'confirmed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order cannot be cancelled',
                ], 422);
            }

            $order->update(['status' => 'cancelled']);

            // Send cancellation notifications
            try {
                $notificationService = app(NotificationService::class);

                // Notify the buyer
                $notificationService->orderCancelled($order->buyer, $order, 'buyer');

                // Notify the farmer if they are not the one cancelling
                if ($user->id !== $order->product->farmer_id) {
                    $notificationService->orderCancelled($order->product->farmer, $order, 'farmer');
                }
            } catch (\Exception $e) {
                \Log::error('Error sending cancellation notification: ' . $e->getMessage());
                // Don't fail the cancellation if notification fails
            }

            return response()->json([
                'success' => true,
                'message' => 'Order cancelled successfully',
                'data' => $order->load(['buyer', 'product']),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error cancelling order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error cancelling order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Write a review for an order
     */
    public function review(Request $request, Order $order): JsonResponse
    {
        try {
            $request->validate([
                'rating' => 'required|integer|min:1|max:5',
                'comment' => 'nullable|string|max:500',
            ]);

            // Check if user is the buyer
            if (auth()->id() !== $order->buyer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the buyer can review this order',
                ], 403);
            }

            // Check if order is delivered
            if ($order->status !== 'delivered') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only review delivered orders',
                ], 422);
            }

            // Check if review already exists
            if ($order->review) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review already exists for this order',
                ], 422);
            }

            $review = Review::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'rating' => $request->rating,
                'comment' => $request->comment,
            ]);

            // Send review notification to farmer and admins
            try {
                $notificationService = app(NotificationService::class);
                $notificationService->newReview($order->product->farmer, $order, $review);
            } catch (\Exception $e) {
                \Log::error('Error sending review notification: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully',
                'data' => $review->load('user'),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error submitting review: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error submitting review: ' . $e->getMessage(),
            ], 500);
        }
    }
}
