<?php

// app/Services/NotificationService.php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Get all admin users
     */
    private function getAdminUsers(): array
    {
        return User::where('role', 'admin')->get()->all();
    }

    /**
     * Send notification to all admins
     */
    public function sendToAdmins(
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $icon = null,
        ?string $link = null
    ): void {
        $admins = $this->getAdminUsers();
        foreach ($admins as $admin) {
            $this->send($admin, $type, $title, $message, $data, $icon, $link);
        }
    }

    /**
     * Get the appropriate base URL for a user
     */
    private function getBaseUrl(bool $isAdmin): string
    {
        if ($isAdmin) {
            return '/app/admin';
        }
        return '/app';
    }

    /**
     * Generate a notification link based on user role. Takes the
     * admin/non-admin distinction as a plain bool (rather than a full
     * User model) so this can also be used by sendManyToUserIds() below,
     * which deliberately never loads full User rows for its recipients.
     */
    private function generateLink(bool $isAdmin, string $path): string
    {
        // If path already starts with /app, return as-is
        if (str_starts_with($path, '/app')) {
            return $path;
        }

        $baseUrl = $this->getBaseUrl($isAdmin);

        // Clean the path - remove leading slash
        $cleanPath = ltrim($path, '/');

        // For admin users, check if the path already has 'admin' prefix
        if ($isAdmin && str_starts_with($cleanPath, 'admin/')) {
            // Remove 'admin/' from the path to avoid duplication
            $cleanPath = substr($cleanPath, 6);
        }

        return "{$baseUrl}/{$cleanPath}";
    }

    /**
     * Send a notification to a user
     */
    public function send(
        User $user,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $icon = null,
        ?string $link = null
    ): Notification {
        // Only generate link if one is provided
        if ($link) {
            // If link doesn't start with /app or http, generate it
            if (!str_starts_with($link, '/app') && !str_starts_with($link, 'http')) {
                $link = $this->generateLink($user->isAdmin(), $link);
            }
        }

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'icon' => $icon,
            'link' => $link,
            'is_read' => false,
        ]);

        Log::info('Notification created for user ' . $user->id . ' with link: ' . $link);

        return $notification;
    }

    /**
     * Send notification to multiple users
     */
    public function sendToMany(array $users, string $type, string $title, string $message, array $data = [], ?string $icon = null, ?string $link = null): void
    {
        foreach ($users as $user) {
            $this->send($user, $type, $title, $message, $data, $icon, $link);
        }
    }

    /**
     * Same notification, sent to many users, in one bulk INSERT instead
     * of one Notification::create() per recipient. For a recipient list
     * that can be large (e.g. every buyer on a new listing — see
     * newProductListed() below), a per-row create() means one full model
     * load plus one round-trip write per user; this needs neither.
     *
     * Only takes user IDs (never loads the User rows), so it deliberately
     * assumes none of the recipients are admins — true for every current
     * caller (buyers only). generateLink()'s admin-path handling would
     * otherwise differ per recipient; sendToAdmins()/send() remain the
     * right call for any admin-inclusive audience.
     *
     * Bypasses Eloquent model events/casts (insert() is a raw query
     * builder call), so timestamps and the JSON `data` column are set
     * explicitly here to match what Notification::create() produces.
     * Chunked to keep each INSERT's parameter count well under
     * PostgreSQL/PDO limits even for a large recipient list.
     */
    public function sendManyToUserIds(array $userIds, string $type, string $title, string $message, array $data = [], ?string $icon = null, ?string $link = null): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if (empty($userIds)) {
            return;
        }

        if ($link && !str_starts_with($link, '/app') && !str_starts_with($link, 'http')) {
            $link = $this->generateLink(false, $link);
        }

        $now = now();
        $encodedData = json_encode($data);

        $rows = array_map(fn (int $userId) => [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $encodedData,
            'icon' => $icon,
            'link' => $link,
            'is_read' => false,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $userIds);

        foreach (array_chunk($rows, 500) as $chunk) {
            Notification::insert($chunk);
        }

        Log::info('Batch notification (' . $type . ') created for ' . count($userIds) . ' users');
    }

    /**
     * Send order placed notification to farmer AND admins
     */
    public function orderPlaced(User $farmer, $order): Notification
    {
        // Send to farmer
        $farmerNotification = $this->send(
            $farmer,
            'order_placed',
            'New Order Received! 🛒',
            "You have received a new order for {$order->product->name} from {$order->buyer->name}.",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'buyer_id' => $order->buyer_id,
                'quantity' => $order->quantity,
                'total_price' => $order->total_price,
            ],
            '🛒',
            "/orders/{$order->id}"
        );

        // Send to all admins - Use regular order details page (admin has access)
        $this->sendToAdmins(
            'order_placed',
            'New Order Placed! 🛒',
            "A new order has been placed for {$order->product->name} by {$order->buyer->name}.",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'buyer_id' => $order->buyer_id,
                'farmer_id' => $farmer->id,
                'quantity' => $order->quantity,
                'total_price' => $order->total_price,
            ],
            '🛒',
            "/orders/{$order->id}" // Admin will see the regular order details page
        );

        return $farmerNotification;
    }

    /**
     * Send order confirmed notification to buyer AND admins
     */
    public function orderConfirmed(User $buyer, $order): Notification
    {
        // Send to buyer
        $buyerNotification = $this->send(
            $buyer,
            'order_confirmed',
            'Order Confirmed! ✅',
            "Your order for {$order->product->name} has been confirmed by the farmer.",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'farmer_id' => $order->product->farmer_id,
            ],
            '✅',
            "/orders/{$order->id}"
        );

        // Send to all admins - Use regular order details page
        $this->sendToAdmins(
            'order_confirmed',
            'Order Confirmed ✅',
            "Order #{$order->id} for {$order->product->name} has been confirmed by the farmer.",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'farmer_id' => $order->product->farmer_id,
                'buyer_id' => $buyer->id,
            ],
            '✅',
            "/orders/{$order->id}"
        );

        return $buyerNotification;
    }

    /**
     * Send order shipped notification to buyer AND admins
     */
    public function orderShipped(User $buyer, $order): Notification
    {
        // Send to buyer
        $buyerNotification = $this->send(
            $buyer,
            'order_shipped',
            'Order Shipped! 🚚',
            "Your order for {$order->product->name} has been shipped.",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
            ],
            '🚚',
            "/orders/{$order->id}"
        );

        // Send to all admins - Use regular order details page
        $this->sendToAdmins(
            'order_shipped',
            'Order Shipped 🚚',
            "Order #{$order->id} for {$order->product->name} has been shipped.",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
            ],
            '🚚',
            "/orders/{$order->id}"
        );

        return $buyerNotification;
    }

    /**
     * Send order delivered notification to buyer AND admins
     */
    public function orderDelivered(User $buyer, $order): Notification
    {
        // Send to buyer
        $buyerNotification = $this->send(
            $buyer,
            'order_delivered',
            'Order Delivered! 📦',
            "Your order for {$order->product->name} has been delivered. Please leave a review!",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
            ],
            '📦',
            "/orders/{$order->id}"
        );

        // Send to all admins - Use regular order details page
        $this->sendToAdmins(
            'order_delivered',
            'Order Delivered 📦',
            "Order #{$order->id} for {$order->product->name} has been delivered.",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
            ],
            '📦',
            "/orders/{$order->id}"
        );

        return $buyerNotification;
    }

    /**
     * Send order cancelled notification to farmer/buyer AND admins
     */
    public function orderCancelled(User $user, $order, string $role): Notification
    {
        $message = $role === 'farmer'
            ? "The order for {$order->product->name} has been cancelled by the buyer."
            : "Your order for {$order->product->name} has been cancelled.";

        // Send to user (farmer or buyer)
        $userNotification = $this->send(
            $user,
            'order_cancelled',
            'Order Cancelled ❌',
            $message,
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
            ],
            '❌',
            "/orders/{$order->id}"
        );

        // Send to all admins - Use regular order details page
        $this->sendToAdmins(
            'order_cancelled',
            'Order Cancelled ❌',
            "Order #{$order->id} for {$order->product->name} has been cancelled.",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'cancelled_by' => $role,
            ],
            '❌',
            "/orders/{$order->id}"
        );

        return $userNotification;
    }

    /**
     * Send farmer verification notification to admins
     */
    public function farmerVerificationRequest(User $farmer): void
    {
        $admins = $this->getAdminUsers();
        foreach ($admins as $admin) {
            $this->send(
                $admin,
                'farmer_verification_request',
                'New Farmer Verification Request! 👨‍🌾',
                "{$farmer->name} has requested to become a verified farmer. Please review their application.",
                [
                    'farmer_id' => $farmer->id,
                    'farmer_name' => $farmer->name,
                    'farmer_email' => $farmer->email,
                ],
                '👨‍🌾',
                "/farmers/verification" // Will become /app/admin/farmers/verification
            );
        }
    }

    /**
     * Send new user registration notification to admins
     */
    public function userRegistered(User $newUser): void
    {
        $this->sendToAdmins(
            'user_registered',
            'New User Registered! 👤',
            "A new user has registered: {$newUser->name} ({$newUser->email}) as a {$newUser->role}.",
            [
                'user_id' => $newUser->id,
                'user_name' => $newUser->name,
                'user_email' => $newUser->email,
                'user_role' => $newUser->role,
            ],
            '👤',
            "/users" // Will become /app/admin/users
        );
    }

    /**
     * Send new product listing notification to admins AND buyers.
     * $buyerIds is a plain list of user IDs (not hydrated User models) —
     * see sendManyToUserIds() for why.
     */
    public function newProductListed(array $buyerIds, $product): void
    {
        // Send to buyers who follow this farmer or category
        $this->sendManyToUserIds(
            $buyerIds,
            'new_product',
            'New Product Available! 🌾',
            "A new product has been listed: {$product->name} by {$product->farmer->name}.",
            [
                'product_id' => $product->id,
                'farmer_id' => $product->farmer_id,
            ],
            '🌾',
            "/products/{$product->id}"
        );

        // Send to all admins
        $this->sendToAdmins(
            'new_product',
            'New Product Listed 🌾',
            "{$product->farmer->name} has listed a new product: {$product->name}.",
            [
                'product_id' => $product->id,
                'farmer_id' => $product->farmer_id,
                'farmer_name' => $product->farmer->name,
            ],
            '🌾',
            "/products" // Will become /app/admin/products
        );
    }

    /**
     * Send low stock notification to farmer AND admins
     */
    public function lowStock(User $farmer, $product): Notification
    {
        // Send to farmer
        $farmerNotification = $this->send(
            $farmer,
            'low_stock',
            'Low Stock Alert! ⚠️',
            "Your product {$product->name} is running low. Only {$product->quantity} {$product->unit} remaining.",
            [
                'product_id' => $product->id,
                'quantity' => $product->quantity,
            ],
            '⚠️',
            "/products"
        );

        // Send to all admins
        $this->sendToAdmins(
            'low_stock',
            'Low Stock Alert ⚠️',
            "Product {$product->name} by {$farmer->name} is running low. Only {$product->quantity} {$product->unit} remaining.",
            [
                'product_id' => $product->id,
                'farmer_id' => $farmer->id,
                'farmer_name' => $farmer->name,
                'quantity' => $product->quantity,
            ],
            '⚠️',
            "/products" // Will become /app/admin/products
        );

        return $farmerNotification;
    }

    /**
     * Send new review notification to farmer AND admins
     */
    public function newReview(User $farmer, $order, $review): Notification
    {
        // Send to farmer
        $farmerNotification = $this->send(
            $farmer,
            'new_review',
            'New Review Received! ⭐',
            "{$order->buyer->name} left a {$review->rating}⭐ review for your product {$order->product->name}.",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'rating' => $review->rating,
            ],
            '⭐',
            "/orders/{$order->id}"
        );

        // Send to all admins
        $this->sendToAdmins(
            'new_review',
            'New Review Posted ⭐',
            "{$order->buyer->name} left a {$review->rating}⭐ review for {$order->product->name} by {$farmer->name}.",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'farmer_id' => $farmer->id,
                'rating' => $review->rating,
            ],
            '⭐',
            "/orders/{$order->id}" // Will become /app/admin/orders/{id} for admin
        );

        return $farmerNotification;
    }

    /**
     * Send farmer verified notification to farmer AND admins
     */
    public function farmerVerified(User $farmer): Notification
    {
        // Send to farmer
        $farmerNotification = $this->send(
            $farmer,
            'farmer_verified',
            'Account Verified! 🎉',
            'Your farmer account has been verified. You can now start listing products.',
            [],
            '✅',
            "/dashboard"
        );

        // Send to all admins
        $this->sendToAdmins(
            'farmer_verified',
            'Farmer Verified ✅',
            "{$farmer->name} has been verified as a farmer.",
            [
                'farmer_id' => $farmer->id,
                'farmer_name' => $farmer->name,
            ],
            '✅',
            "/farmers/verification" // Will become /app/admin/farmers/verification
        );

        return $farmerNotification;
    }

    /**
     * Send dispute opened notification to the farmer AND admins
     */
    public function disputeOpened(User $farmer, $order, $dispute): Notification
    {
        $farmerNotification = $this->send(
            $farmer,
            'dispute_opened',
            'Issue Reported ⚠️',
            "{$order->buyer->name} reported an issue with their order for {$order->product->name}.",
            [
                'order_id' => $order->id,
                'dispute_id' => $dispute->id,
                'reason' => $dispute->reason,
            ],
            '⚠️',
            "/orders/{$order->id}"
        );

        $this->sendToAdmins(
            'dispute_opened',
            'New Dispute Reported ⚠️',
            "{$order->buyer->name} reported an issue with order #{$order->id} for {$order->product->name}.",
            [
                'order_id' => $order->id,
                'dispute_id' => $dispute->id,
                'farmer_id' => $farmer->id,
                'reason' => $dispute->reason,
            ],
            '⚠️',
            "/disputes" // Will become /app/admin/disputes for admin
        );

        return $farmerNotification;
    }

    /**
     * Send dispute resolved/rejected notification to the buyer who reported it
     */
    public function disputeResolved(User $buyer, $order, $dispute): Notification
    {
        $isResolved = $dispute->status === 'resolved';
        $message = "Your reported issue for {$order->product->name} has been "
            . ($isResolved ? 'resolved' : 'rejected') . '.'
            . ($dispute->admin_note ? " Note: {$dispute->admin_note}" : '');

        return $this->send(
            $buyer,
            'dispute_resolved',
            $isResolved ? 'Dispute Resolved ✅' : 'Dispute Rejected ❌',
            $message,
            [
                'order_id' => $order->id,
                'dispute_id' => $dispute->id,
                'status' => $dispute->status,
            ],
            $isResolved ? '✅' : '❌',
            "/orders/{$order->id}"
        );
    }

    /**
     * Send farmer rejected notification to farmer AND admins
     */
    public function farmerRejected(User $farmer, string $reason): Notification
    {
        // Send to farmer
        $farmerNotification = $this->send(
            $farmer,
            'farmer_rejected',
            'Verification Rejected ❌',
            "Your farmer account verification was rejected. Reason: {$reason}",
            ['reason' => $reason],
            '❌',
            "/profile"
        );

        // Send to all admins
        $this->sendToAdmins(
            'farmer_rejected',
            'Farmer Rejected ❌',
            "{$farmer->name}'s verification request was rejected. Reason: {$reason}",
            [
                'farmer_id' => $farmer->id,
                'farmer_name' => $farmer->name,
                'reason' => $reason,
            ],
            '❌',
            "/farmers/verification" // Will become /app/admin/farmers/verification
        );

        return $farmerNotification;
    }
}
