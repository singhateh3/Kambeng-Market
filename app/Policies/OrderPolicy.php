<?php

// app/Policies/OrderPolicy.php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    private function isOwningFarmer(User $user, Order $order): bool
    {
        return $user->isFarmer() && $order->product->farmer_id === $user->id;
    }

    /**
     * View a single order — buyer, the farmer who owns the product, or admin.
     */
    public function view(User $user, Order $order): bool
    {
        return $user->id === $order->buyer_id
            || $this->isOwningFarmer($user, $order)
            || $user->isAdmin();
    }

    /**
     * Advance order status — the owning farmer or admin only, never the buyer.
     */
    public function updateStatus(User $user, Order $order): bool
    {
        return $this->isOwningFarmer($user, $order) || $user->isAdmin();
    }

    /**
     * Cancel an order — buyer, the owning farmer, or admin.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $user->id === $order->buyer_id
            || $this->isOwningFarmer($user, $order)
            || $user->isAdmin();
    }

    /**
     * Review an order — the buyer only. No admin/farmer bypass — a review
     * is the buyer's own opinion, not something anyone else can submit on
     * their behalf.
     */
    public function review(User $user, Order $order): bool
    {
        return $user->id === $order->buyer_id;
    }

    /**
     * Report an issue with an order — the buyer only. See DisputeTest for
     * the authorization tests covering this.
     */
    public function report(User $user, Order $order): bool
    {
        return $user->id === $order->buyer_id;
    }

    /**
     * Confirm "everything is okay" to release the farmer's payout — the
     * buyer only.
     */
    public function confirm(User $user, Order $order): bool
    {
        return $user->id === $order->buyer_id;
    }

    /**
     * Retry a failed farmer payout — admin only. Redundant with the
     * 'admin' route middleware already guarding AdminOrderController,
     * same defense-in-depth rationale as DisputePolicy::resolve().
     */
    public function retryPayout(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }

    /**
     * Confirm a refund was actually processed through ModemPay's own
     * dashboard — admin only.
     */
    public function confirmRefund(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }
}
