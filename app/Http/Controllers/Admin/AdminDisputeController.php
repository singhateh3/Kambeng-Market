<?php

// app/Http/Controllers/Admin/AdminDisputeController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Models\PaymentTransaction;
use App\Services\NotificationService;
use App\Services\PayoutReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDisputeController extends Controller
{
    private const RELATIONS = ['order.buyer', 'order.product.farmer', 'order.review', 'reporter', 'reviewer'];

    /**
     * Get all disputes, optionally filtered by status.
     */
    public function index(Request $request): JsonResponse
    {
        $disputes = Dispute::with(self::RELATIONS)
            ->when($request->status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => DisputeResource::collection($disputes),
            'meta' => [
                'current_page' => $disputes->currentPage(),
                'last_page' => $disputes->lastPage(),
                'per_page' => $disputes->perPage(),
                'total' => $disputes->total(),
            ],
        ]);
    }

    /**
     * Get a specific dispute.
     */
    public function show(Dispute $dispute): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new DisputeResource($dispute->load(self::RELATIONS)),
        ]);
    }

    /**
     * Move a dispute through its lifecycle: open -> under_review ->
     * resolved/rejected. admin_note/reviewed_by/reviewed_at are only
     * persisted on the terminal resolved/rejected transition.
     *
     * Resolving a dispute never implicitly means a refund — the admin
     * makes an explicit financial decision (no_refund/full_refund/
     * partial_refund) alongside the status change, recorded in the
     * ledger/audit trail via PaymentTransaction::recordPendingRefund().
     * A rejected dispute can only ever be no_refund — "rejected" means no
     * legitimate issue was found, so nothing to refund.
     */
    public function updateStatus(Request $request, Dispute $dispute): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:under_review,resolved,rejected',
            'admin_note' => 'required_if:status,resolved,rejected|nullable|string|max:1000',
            'refund_decision' => ['required_if:status,resolved,rejected', 'nullable', 'in:' . implode(',', Dispute::REFUND_DECISIONS)],
            'refund_amount' => 'required_if:refund_decision,full_refund,partial_refund|nullable|numeric|min:0.01',
        ]);

        $validTransitions = [
            'open' => ['under_review'],
            'under_review' => ['resolved', 'rejected'],
            'resolved' => [],
            'rejected' => [],
        ];

        $newStatus = $request->status;

        if (!in_array($newStatus, $validTransitions[$dispute->status] ?? [])) {
            return response()->json([
                'success' => false,
                'message' => "Cannot transition from '{$dispute->status}' to '{$newStatus}'",
            ], 422);
        }

        if ($newStatus === 'rejected' && $request->refund_decision !== 'no_refund') {
            return response()->json([
                'success' => false,
                'message' => 'A rejected dispute cannot include a refund',
            ], 422);
        }

        $order = $dispute->order;

        if ($request->filled('refund_amount') && (float) $request->refund_amount > (float) $order->total_price) {
            return response()->json([
                'success' => false,
                'message' => 'Refund amount cannot exceed the order total',
            ], 422);
        }

        $updates = ['status' => $newStatus];
        $isTerminal = in_array($newStatus, ['resolved', 'rejected']);
        if ($isTerminal) {
            $updates['admin_note'] = $request->admin_note;
            $updates['reviewed_by'] = $request->user()->id;
            $updates['reviewed_at'] = now();
            $updates['refund_decision'] = $request->refund_decision;
            $updates['refund_amount'] = $request->refund_amount;
        }

        $dispute->update($updates);

        if ($isTerminal && in_array($request->refund_decision, ['full_refund', 'partial_refund'], true)) {
            $type = $request->refund_decision === 'full_refund' ? 'refund' : 'partial_refund';
            PaymentTransaction::recordPendingRefund($order, (float) $request->refund_amount, $type, "Dispute #{$dispute->id} resolution");

            if ($type === 'refund') {
                // Nothing left owed to the farmer for this order.
                $order->update(['payout_status' => 'voided']);
            }
            // A partial refund doesn't void the payout — the farmer's
            // eventual amount is simply reduced, computed from the ledger
            // at release time (see PayoutReleaseService), never by
            // mutating the order's original snapshot.
        }

        // Rejected past the buyer-protection window releases immediately
        // rather than waiting for the next scheduled run — the dispute
        // was the only thing blocking it (see Order::hasActiveDispute()).
        if ($newStatus === 'rejected'
            && $order->payout_status === 'pending_release'
            && $order->delivered_at
            && $order->delivered_at->addDays((int) config('commission.auto_release_days'))->isPast()
        ) {
            app(PayoutReleaseService::class)->release($order, 'dispute_rejected_post_window');
        }

        $dispute->load(self::RELATIONS);

        if ($isTerminal) {
            try {
                $notificationService = app(NotificationService::class);
                $notificationService->disputeResolved($dispute->order->buyer, $dispute->order, $dispute);
            } catch (\Exception $e) {
                \Log::error('Error sending dispute resolved notification: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Dispute updated successfully',
            'data' => new DisputeResource($dispute),
        ]);
    }
}
