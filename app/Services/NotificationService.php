<?php

// app/Services/NotificationService.php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Get the appropriate base URL for a user
     */
    private function getBaseUrl(User $user): string
    {
        // Check if user is admin
        if ($user->hasRole('admin') || $user->isAdmin()) {
            return '/app/admin'; // Updated to match your routing structure
        }
        return '/app';
    }

    /**
     * Generate a notification link based on user role
     */
    private function generateLink(User $user, string $path): string
    {
        $baseUrl = $this->getBaseUrl($user);
        $cleanPath = ltrim($path, '/');
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
        // If link is provided, ensure it has the correct base URL for the user
        if ($link && !str_starts_with($link, '/app')) {
            $link = $this->generateLink($user, $link);
        }

        return Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'icon' => $icon,
            'link' => $link,
            'is_read' => false,
        ]);
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
     * Send order placed notification to farmer
     */
    public function orderPlaced(User $farmer, $order): Notification
    {
        return $this->send(
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
            "/app/orders/{$order->id}"
        );
    }

    /**
     * Send order confirmed notification to buyer
     */
    public function orderConfirmed(User $buyer, $order): Notification
    {
        return $this->send(
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
            "/app/orders/{$order->id}"
        );
    }

    /**
     * Send order shipped notification to buyer
     */
    public function orderShipped(User $buyer, $order): Notification
    {
        return $this->send(
            $buyer,
            'order_shipped',
            'Order Shipped! 🚚',
            "Your order for {$order->product->name} has been shipped.",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
            ],
            '🚚',
            "/app/orders/{$order->id}"
        );
    }

    /**
     * Send order delivered notification to buyer
     */
    public function orderDelivered(User $buyer, $order): Notification
    {
        return $this->send(
            $buyer,
            'order_delivered',
            'Order Delivered! 📦',
            "Your order for {$order->product->name} has been delivered. Please leave a review!",
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
            ],
            '📦',
            "/app/orders/{$order->id}"
        );
    }

    /**
     * Send order cancelled notification
     */
    public function orderCancelled(User $user, $order, string $role): Notification
    {
        $message = $role === 'farmer'
            ? "The order for {$order->product->name} has been cancelled by the buyer."
            : "Your order for {$order->product->name} has been cancelled.";

        return $this->send(
            $user,
            'order_cancelled',
            'Order Cancelled ❌',
            $message,
            [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
            ],
            '❌',
            "/app/orders/{$order->id}"
        );
    }

    /**
     * Send farmer verification notification to admins
     */
    public function farmerVerificationRequest(User $farmer, User $admin): Notification
    {
        return $this->send(
            $admin,
            'farmer_verification_request',
            'New Farmer Verification Request! 👨‍🌾',
            "{$farmer->name} has requested to become a verified farmer. Please review their application.",
            [
                'farmer_id' => $farmer->id,
                'farmer_name' => $farmer->name,
                'farmer_email' => $farmer->email,
                'farm_name' => $farmer->farmerProfile?->farm_name ?? 'N/A',
                'farm_location' => $farmer->farmerProfile?->farm_location ?? 'N/A',
            ],
            '👨‍🌾',
            "/app/admin/farmers/verification" // Updated to match your routing
        );
    }

    /**
     * Send farmer verified notification
     */
    public function farmerVerified(User $farmer): Notification
    {
        return $this->send(
            $farmer,
            'farmer_verified',
            'Account Verified! 🎉',
            'Your farmer account has been verified. You can now start listing products.',
            [],
            '✅',
            "/app/dashboard"
        );
    }

    /**
     * Send farmer rejected notification
     */
    public function farmerRejected(User $farmer, string $reason): Notification
    {
        return $this->send(
            $farmer,
            'farmer_rejected',
            'Verification Rejected ❌',
            "Your farmer account verification was rejected. Reason: {$reason}",
            ['reason' => $reason],
            '❌',
            "/app/profile"
        );
    }

    /**
     * Send new product listing notification (to buyers)
     */
    public function newProductListed(array $buyers, $product): void
    {
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
            "/app/products/{$product->id}"
        );
    }

    /**
     * Send low stock notification to farmer
     */
    public function lowStock(User $farmer, $product): Notification
    {
        return $this->send(
            $farmer,
            'low_stock',
            'Low Stock Alert! ⚠️',
            "Your product {$product->name} is running low. Only {$product->quantity} {$product->unit} remaining.",
            [
                'product_id' => $product->id,
                'quantity' => $product->quantity,
            ],
            '⚠️',
            "/app/products"
        );
    }

    /**
     * Send new review notification to farmer
     */
    public function newReview(User $farmer, $order, $review): Notification
    {
        return $this->send(
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
            "/app/orders/{$order->id}"
        );
    }
}
