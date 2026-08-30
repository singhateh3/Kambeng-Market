<?php

// app/Policies/OrderPolicy.php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Whether $user may report an issue against $order. Scoped to the
     * dispute-reporting flow only — the rest of OrderController keeps its
     * existing inline ownership checks (isBuyer/isFarmer/isAdmin), which
     * this policy deliberately does not replace.
     */
    public function report(User $user, Order $order): bool
    {
        return $user->id === $order->buyer_id;
    }
}
