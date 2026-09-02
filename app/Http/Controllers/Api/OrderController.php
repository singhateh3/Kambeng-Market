<?php

// app/Http/Controllers/Api/OrderController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Review;
use App\Services\ModemPayClient;
use App\Services\NotificationService;
use App\Services\PayoutReleaseService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * Get orders for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $query = Order::with(['buyer', 'product', 'product.farmer', 'review', 'dispute']);

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
     * Start a new order. ModemPay is the only payment method — nothing
     * here accepts a client-supplied payment_method, unlike the old COD
     * flow. The order is created in 'awaiting_payment' and is not real /
     * farmer-visible (no orderPlaced() notification, doesn't appear in the
     * farmer's order list) until a verified successful ModemPay webhook
     * moves it to 'pending' — see ModemPayWebhookController.
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
                'delivery_address' => 'required_if:delivery_method,farmer_delivery|nullable|string|max:500',
                'special_instructions' => 'nullable|string|max:500',
            ]);

            $product = Product::findOrFail($request->product_id);

            if (!$product->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product is not available',
                ], 422);
            }

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
                'status' => 'awaiting_payment',
                'order_date' => now(),
                'payment_method' => 'modempay',
                'payment_status' => 'pending',
            ];

            if ($request->delivery_method === 'pickup') {
                $orderData['pickup_date'] = $request->pickup_date;
                $orderData['delivery_deadline'] = null;
                $orderData['delivery_address'] = null;
            } else {
                $orderData['delivery_deadline'] = $request->delivery_deadline;
                $orderData['pickup_date'] = null;
                $orderData['delivery_address'] = $request->delivery_address;
            }

            // Order creation and payment-intent creation succeed or fail
            // together — a checkout that never got a payment_link is not
            // left behind as an orphaned awaiting_payment order.
            $order = DB::transaction(function () use ($orderData, $totalPrice) {
                $order = new Order($orderData);
                $order->applyCommissionSnapshot();
                $order->save();

                $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
                $returnUrl = "{$frontendUrl}/app/orders/{$order->id}/payment-return?status=success";
                $cancelUrl = "{$frontendUrl}/app/orders/{$order->id}/payment-return?status=cancelled";

                $modemPay = app(ModemPayClient::class);
                $response = $modemPay->createPaymentIntent([
                    'amount' => (string) $totalPrice,
                    'currency' => 'GMD',
                    'title' => "Kambeng Market order #{$order->id}",
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                    'metadata' => ['order_id' => $order->id],
                ]);

                // payment_intent_id and intent_secret are two DIFFERENT,
                // both-required identifiers — confirmed live (Task 11).
                // payment_intent_id is the stable UUID used for general
                // correlation (webhooks, the ledger, reconciliation);
                // intent_secret is a separate token required specifically
                // by ModemPayClient::verifyPaymentIntent() and nowhere
                // else. Storing only one, as the original implementation
                // did, silently broke whichever use needed the other.
                $paymentIntentId = $response['data']['payment_intent_id'] ?? null;
                $intentSecret = $response['data']['intent_secret'] ?? null;
                $paymentLink = $response['data']['payment_link'] ?? null;

                if (!$paymentIntentId || !$intentSecret || !$paymentLink) {
                    throw new \RuntimeException('ModemPay did not return the expected payment intent fields.');
                }

                $order->update([
                    'modempay_intent_id' => $paymentIntentId,
                    'modempay_intent_secret' => $intentSecret,
                ]);

                // Idempotency-Key was confirmed required for ModemPay's
                // Transfer API but not confirmed either way for Payment
                // Intent creation — not sent here since it isn't
                // documented for this endpoint, but this ledger row is
                // still written for our own audit trail regardless.
                PaymentTransaction::create([
                    'order_id' => $order->id,
                    'type' => 'charge',
                    'amount' => $totalPrice,
                    'currency' => 'GMD',
                    'commission_amount' => $order->commission_amount,
                    'status' => 'pending',
                    'modempay_reference' => $paymentIntentId,
                    'idempotency_key' => (string) Str::uuid(),
                    'metadata' => ['payment_link' => $paymentLink],
                ]);

                $order->payment_link = $paymentLink;

                return $order;
            });

            return response()->json([
                'success' => true,
                'message' => 'Checkout started',
                'data' => [
                    'order_id' => $order->id,
                    'payment_link' => $order->payment_link,
                    'status' => $order->status,
                ],
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

            if ($user->cannot('view', $order)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this order',
                ], 403);
            }

            $order->load(['buyer', 'product', 'product.farmer', 'review', 'dispute']);

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

            if ($user->cannot('updateStatus', $order)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the farmer can update order status',
                ], 403);
            }

            $currentStatus = $order->status;
            $newStatus = $request->status;

            if (!$order->canTransitionTo($newStatus)) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot transition from '{$currentStatus}' to '{$newStatus}'",
                ], 422);
            }

            // payment_status is derived as a side effect of this same
            // transition (delivered -> paid for COD, cancelled ->
            // cancelled) rather than being independently settable — there
            // is no request field for it, so a farmer can only ever move
            // it by taking the same action (confirm/ship/deliver/cancel)
            // they're already authorized to take above, for the reason
            // they're already taking it.
            $updates = ['status' => $newStatus];
            if ($derivedPaymentStatus = $order->derivedPaymentStatus($newStatus)) {
                $updates['payment_status'] = $derivedPaymentStatus;
            }

            if ($newStatus === 'delivered') {
                $updates['delivered_at'] = now();
                // Starts the 3-day buyer-protection clock. COD orders
                // (grandfathered, pre-cutover) have no payout mechanism at
                // all — stay 'not_applicable' permanently.
                if ($order->payment_method === 'modempay') {
                    $updates['payout_status'] = 'pending_release';
                }
            }

            if ($newStatus === 'cancelled' && $order->payment_method === 'modempay' && $order->payment_status === 'paid') {
                PaymentTransaction::recordPendingRefund($order, (float) $order->total_price, 'refund', 'Order cancelled by farmer/admin after payment succeeded');
            }

            $order->update($updates);

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

            if ($user->cannot('cancel', $order)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to cancel this order',
                ], 403);
            }

            // Pre-shipment cancellation stays self-service (pending/
            // confirmed only) — from 'shipped' onward the buyer must use
            // the dispute process instead.
            if (!in_array($order->status, ['pending', 'confirmed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order cannot be cancelled. Please use the dispute process for shipped or delivered orders.',
                ], 422);
            }

            $newPaymentStatus = $order->derivedPaymentStatus('cancelled');
            $order->update(array_filter([
                'status' => 'cancelled',
                'payment_status' => $newPaymentStatus,
            ], fn ($v) => $v !== null));

            // A modempay order that already collected payment needs an
            // actual refund, not a relabel — derivedPaymentStatus()
            // deliberately returns null for this case so it isn't silently
            // mislabeled here.
            if ($order->payment_method === 'modempay' && $order->payment_status === 'paid') {
                PaymentTransaction::recordPendingRefund($order, (float) $order->total_price, 'refund', 'Buyer self-cancelled before shipment');
            }

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
            if ($request->user()->cannot('review', $order)) {
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

    /**
     * Report an issue with an order (buyer only).
     */
    public function report(Request $request, Order $order): JsonResponse
    {
        try {
            if ($request->user()->cannot('report', $order)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the buyer can report an issue with this order',
                ], 403);
            }

            $request->validate([
                'reason' => 'required|in:' . implode(',', Dispute::REASONS),
                'description' => 'nullable|string|max:1000',
            ]);

            // Reporting stands in for a dispute mechanism on orders that
            // have actually progressed — pending orders can simply be
            // cancelled (OrderController::cancel()), and cancelled orders
            // are already a terminal, resolved state with nothing to
            // dispute.
            if (!in_array($order->status, Dispute::REPORTABLE_ORDER_STATUSES)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order cannot be reported in its current status.',
                ], 422);
            }

            if ($order->dispute && in_array($order->dispute->status, Dispute::ACTIVE_STATUSES)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order already has an active dispute.',
                ], 422);
            }

            try {
                $dispute = Dispute::create([
                    'order_id' => $order->id,
                    'reported_by' => $request->user()->id,
                    'reason' => $request->reason,
                    'description' => $request->description,
                    'status' => 'open',
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // The check above already covers the normal case — this
                // only fires if a second request for the same order won the
                // race between that check and this insert. The DB-level
                // partial unique index (see the disputes migration) is what
                // actually stops the duplicate row; this just turns that
                // into the same clean response instead of a 500.
                return response()->json([
                    'success' => false,
                    'message' => 'This order already has an active dispute.',
                ], 422);
            }

            $order->load('product.farmer', 'buyer');

            try {
                $notificationService = app(NotificationService::class);
                $notificationService->disputeOpened($order->product->farmer, $order, $dispute);
            } catch (\Exception $e) {
                \Log::error('Error sending dispute opened notification: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Issue reported successfully',
                'data' => $dispute->load('reporter'),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error reporting order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error reporting order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Buyer confirms "everything is okay" — releases the farmer's payout.
     * Blocked while any dispute is active on the order, regardless of who
     * filed it. If nobody confirms, AutoReleaseFarmerPayouts releases it
     * automatically 3 days after delivery anyway (see PayoutReleaseService).
     */
    public function confirm(Request $request, Order $order): JsonResponse
    {
        try {
            if ($request->user()->cannot('confirm', $order)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the buyer can confirm this order',
                ], 403);
            }

            if ($order->status !== 'delivered') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only delivered orders can be confirmed',
                ], 422);
            }

            if ($order->hasActiveDispute()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order has an active dispute and cannot be confirmed until it is resolved',
                ], 422);
            }

            if ($order->payout_status !== 'pending_release') {
                return response()->json([
                    'success' => false,
                    'message' => 'This order has already been confirmed or is not eligible for confirmation',
                ], 422);
            }

            // Server-controlled, set only here — never from any request input.
            $order->update(['buyer_confirmed_at' => now()]);

            $result = app(PayoutReleaseService::class)->release($order, 'buyer_confirmed');

            if (!$result['success']) {
                \Log::warning("Buyer confirm on order {$order->id} could not release payout: {$result['reason']}");
                return response()->json([
                    'success' => true,
                    'message' => 'Order confirmed. Farmer payout could not be released automatically and will be reviewed by an admin.',
                    'data' => $order->fresh(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order confirmed and farmer payout released',
                'data' => $order->fresh(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error confirming order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error confirming order: ' . $e->getMessage(),
            ], 500);
        }
    }
}
