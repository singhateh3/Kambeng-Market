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
    private function getBaseUrl(User $user): string
    {
        if ($user->isAdmin()) {
            return '/app/admin';
        }
        return '/app';
    }

    /**
     * Generate a notification link based on user role
     * FIXED: Remove the 'admin' prefix from the path if it exists
     */
    private function generateLink(User $user, string $path): string
    {
        // If path already starts with /app, return as-is
        if (str_starts_with($path, '/app')) {
            return $path;
        }

        $baseUrl = $this->getBaseUrl($user);

        // Clean the path - remove leading slash
        $cleanPath = ltrim($path, '/');

        // For admin users, check if the path already has 'admin' prefix
        if ($user->isAdmin() && str_starts_with($cleanPath, 'admin/')) {
            // Remove 'admin/' from the path to avoid duplication
            $cleanPath = substr($cleanPath, 6); // Remove 'admin/'
        }

        $fullLink = "{$baseUrl}/{$cleanPath}";

        Log::info('Generated link for user ' . $user->id . ': ' . $fullLink);

        return $fullLink;
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
                $link = $this->generateLink($user, $link);
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

        // Send to all admins
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
            "/orders/{$order->id}"
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

        // Send to all admins
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

        // Send to all admins
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

        // Send to all admins
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

        // Send to all admins
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
                "/farmers/verification"
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
            "/users"
        );
    }

    /**
     * Send new product listing notification to admins AND buyers
     */
    public function newProductListed(array $buyers, $product): void
    {
        // Send to buyers who follow this farmer or category
        $this->sendToMany(
            $buyers,
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
            "/products"
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
            "/products"
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
            "/orders/{$order->id}"
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
            "/farmers/verification"
        );

        return $farmerNotification;
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
            "/farmers/verification"
        );

        return $farmerNotification;
    }
}
