<?php

// app/Http/Controllers/Admin/AdminPaymentTransactionController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentTransactionController extends Controller
{
    /**
     * The financial ledger, admin-facing. Read-only — nothing here ever
     * writes to a payment_transactions row; see PaymentTransaction/
     * PayoutReleaseService for where rows are actually created/updated.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PaymentTransaction::with('order.buyer', 'order.product.farmer')
            ->when($request->order_id, fn ($q, $orderId) => $q->where('order_id', $orderId))
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status));

        $transactions = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                // Quick commission-revenue summary — sum of commission
                // taken on every succeeded charge, independent of the
                // current page's filters/pagination.
                'total_commission_revenue' => PaymentTransaction::where('type', 'charge')
                    ->where('status', 'succeeded')
                    ->sum('commission_amount'),
            ],
        ]);
    }
}
