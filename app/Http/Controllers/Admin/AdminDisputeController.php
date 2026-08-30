<?php

// app/Http/Controllers/Admin/AdminDisputeController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Services\NotificationService;
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
     */
    public function updateStatus(Request $request, Dispute $dispute): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:under_review,resolved,rejected',
            'admin_note' => 'required_if:status,resolved,rejected|nullable|string|max:1000',
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

        $updates = ['status' => $newStatus];
        $isTerminal = in_array($newStatus, ['resolved', 'rejected']);
        if ($isTerminal) {
            $updates['admin_note'] = $request->admin_note;
            $updates['reviewed_by'] = $request->user()->id;
            $updates['reviewed_at'] = now();
        }

        $dispute->update($updates);
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
