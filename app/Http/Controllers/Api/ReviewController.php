<?php

// app/Http/Controllers/Api/ReviewController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ReviewController extends Controller
{
    /**
     * Get reviews for a product
     */
    public function productReviews($productId): JsonResponse
    {
        try {
            $reviews = Review::with(['user', 'order'])
                ->whereHas('order', function ($query) use ($productId) {
                    $query->where('product_id', $productId);
                })
                ->whereNotNull('rating')
                ->latest()
                ->paginate(10);

            $averageRating = Review::whereHas('order', function ($query) use ($productId) {
                $query->where('product_id', $productId);
            })->avg('rating');

            return response()->json([
                'success' => true,
                'data' => $reviews->items(),
                'meta' => [
                    'current_page' => $reviews->currentPage(),
                    'last_page' => $reviews->lastPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                    'average_rating' => round($averageRating ?? 0, 1),
                    'total_reviews' => $reviews->total(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching product reviews: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching reviews',
            ], 500);
        }
    }

    /**
     * Store a review for an order
     */
    public function store(Request $request, Order $order): JsonResponse
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

            // Send notification to farmer
            try {
                $notificationService = app(NotificationService::class);
                $notificationService->send(
                    $order->product->farmer,
                    'new_review',
                    'New Review Received! ⭐',
                    "{$order->buyer->name} left a {$request->rating}⭐ review for your product {$order->product->name}.",
                    [
                        'order_id' => $order->id,
                        'product_id' => $order->product_id,
                        'rating' => $request->rating,
                    ],
                    '⭐',
                    "/app/orders/{$order->id}"
                );
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

    /**
     * Update a review
     */
    public function update(Request $request, Review $review): JsonResponse
    {
        try {
            $request->validate([
                'rating' => 'required|integer|min:1|max:5',
                'comment' => 'nullable|string|max:500',
            ]);

            // Check if user is the reviewer
            if (auth()->id() !== $review->user_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this review',
                ], 403);
            }

            $review->update([
                'rating' => $request->rating,
                'comment' => $request->comment,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Review updated successfully',
                'data' => $review->load('user'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating review: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a review
     */
    public function destroy(Review $review): JsonResponse
    {
        try {
            // Check if user is the reviewer or admin
            if (auth()->id() !== $review->user_id && !auth()->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this review',
                ], 403);
            }

            $review->delete();

            return response()->json([
                'success' => true,
                'message' => 'Review deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting review: ' . $e->getMessage(),
            ], 500);
        }
    }
}
