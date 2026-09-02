<?php


namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\PayoutReleaseService;
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

        // Same transition rules as the farmer-facing OrderController::
        // updateStatus() — see Order::VALID_STATUS_TRANSITIONS. Previously
        // missing here, which let an admin jump e.g. pending -> delivered
        // in one call and silently trigger the COD-auto-paid derivation
        // below without the order passing through confirmed/shipped.
        if (!$order->canTransitionTo($request->status)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot transition from '{$order->status}' to '{$request->status}'",
            ], 422);
        }

        // Same COD-at-delivery / cancelled derivation as the farmer-facing
        // OrderController::updateStatus() — see Order::derivedPaymentStatus().
        $updates = ['status' => $request->status];
        if ($derivedPaymentStatus = $order->derivedPaymentStatus($request->status)) {
            $updates['payment_status'] = $derivedPaymentStatus;
        }

        if ($request->status === 'delivered') {
            $updates['delivered_at'] = now();
            if ($order->payment_method === 'modempay') {
                $updates['payout_status'] = 'pending_release';
            }
        }

        if ($request->status === 'cancelled' && $order->payment_method === 'modempay' && $order->payment_status === 'paid') {
            PaymentTransaction::recordPendingRefund($order, (float) $order->total_price, 'refund', 'Order cancelled by admin after payment succeeded');
        }

        $order->update($updates);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'data' => new OrderResource($order->load(['buyer', 'product'])),
        ]);
    }

    /**
     * Confirm that a refund recorded in the ledger (via
     * PaymentTransaction::recordPendingRefund()) was actually processed
     * through ModemPay's own dashboard — no public refund API was
     * confirmed to exist, so this step cannot be automated. See
     * PaymentTransaction::recordPendingRefund() for the full reasoning.
     */
    public function confirmRefund(Request $request, Order $order): JsonResponse
    {
        if ($request->user()->cannot('confirmRefund', $order)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $refund = $order->paymentTransactions()
            ->whereIn('type', ['refund', 'partial_refund'])
            ->where('status', 'pending')
            ->latest()
            ->first();

        if (!$refund) {
            return response()->json([
                'success' => false,
                'message' => 'No pending refund found for this order',
            ], 404);
        }

        $refund->update(['status' => 'succeeded']);
        $order->update([
            'payment_status' => $refund->type === 'partial_refund' ? 'partially_refunded' : 'refunded',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Refund confirmed',
            'data' => new OrderResource($order->fresh()->load(['buyer', 'product'])),
        ]);
    }

    /**
     * Retry a failed farmer payout. Resets payout_status back to
     * pending_release first — PayoutReleaseService::release() only ever
     * starts from that state, and a fresh idempotency key/ledger row is
     * created for this attempt (see that service; failed attempts are
     * never mutated or deleted, only superseded by a new row).
     *
     * ModemPay documents no way to look up, retrieve, or search a
     * previously-created transfer by id/idempotency-key/reference —
     * confirmed by checking both the Payouts and Transactions API docs
     * directly (the latter's `type` values are payment/subscription/
     * invoice, no transfer/payout type at all). So if the previous
     * attempt failed with an AMBIGUOUS outcome (no response received —
     * see PayoutReleaseService's ConnectionException branch), there is no
     * automated way to confirm whether it actually sent money before
     * retrying. Retrying that specific case requires the admin to
     * explicitly acknowledge they've manually verified in ModemPay's own
     * dashboard first. A clean rejection (a real HTTP error response —
     * definitely never sent) does not require this extra step.
     */
    public function retryPayout(Request $request, Order $order, PayoutReleaseService $payoutRelease): JsonResponse
    {
        if ($request->user()->cannot('retryPayout', $order)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($order->payout_status !== 'failed') {
            return response()->json([
                'success' => false,
                'message' => 'This order does not have a failed payout to retry',
            ], 422);
        }

        $lastAttempt = $order->paymentTransactions()
            ->where('type', 'payout')
            ->where('status', 'failed')
            ->latest()
            ->first();
        $isAmbiguous = (bool) ($lastAttempt->metadata['ambiguous_outcome'] ?? false);

        if ($isAmbiguous && !$request->boolean('acknowledge_ambiguous_outcome')) {
            return response()->json([
                'success' => false,
                'message' => 'The previous transfer attempt failed with an ambiguous outcome — no response was received from ModemPay, so it may have actually succeeded. ModemPay provides no way to look up a transfer to check. You must manually verify in the ModemPay dashboard that no transfer for this order was already sent before retrying. Resubmit with acknowledge_ambiguous_outcome=true once verified.',
                'requires_acknowledgment' => true,
            ], 422);
        }

        $order->update(['payout_status' => 'pending_release']);

        $result = $payoutRelease->release($order, 'admin_override');

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Payout retry could not be completed: ' . $result['reason'],
                'data' => new OrderResource($order->fresh()),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payout retry initiated',
            'data' => new OrderResource($order->fresh()),
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