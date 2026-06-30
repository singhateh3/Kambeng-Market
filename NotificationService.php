<?php

// app/Services/NotificationService.php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    private function getAdminUsers(): array
    {
        return User::where('role', 'admin')->get()->all();
    }

    public function sendToAdmins(
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $icon = null,
        ?string $link = null
    ): void {
        foreach ($this->getAdminUsers() as $admin) {
            $this->send($admin, $type, $title, $message, $data, $icon, $link);
        }
    }

    public function sendToMany(array $users, string $type, string $title, string $message, array $data = [], ?string $icon = null, ?string $link = null): void
    {
        foreach ($users as $user) {
            $this->send($user, $type, $title, $message, $data, $icon, $link);
        }
    }

    /**
     * Send a notification to a user.
     * Links must be full paths starting with /app — no transformation is done here.
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
        $notification = Notification::create([
            'user_id' => $user->id,
            'type'    => $type,
            'title'   => $title,
            'message' => $message,
            'data'    => $data,
            'icon'    => $icon,
            'link'    => $link,
            'is_read' => false,
        ]);

        Log::info("Notification [{$type}] created for user {$user->id} → {$link}");

        return $notification;
    }

    // -----------------------------------------------------------------------
    // Order notifications
    // -----------------------------------------------------------------------

    public function orderPlaced(User $farmer, $order): Notification
    {
        $farmerNotification = $this->send(
            $farmer,
            'order_placed',
            'New Order Received! 🛒',
            "You have received a new order for {$order->product->name} from {$order->buyer->name}.",
            [
                'order_id'    => $order->id,
                'product_id'  => $order->product_id,
                'buyer_id'    => $order->buyer_id,
                'quantity'    => $order->quantity,
                'total_price' => $order->total_price,
            ],
            '🛒',
            "/app/orders/{$order->id}"
        );

        $this->sendToAdmins(
            'order_placed',
            'New Order Placed! 🛒',
            "A new order has been placed for {$order->product->name} by {$order->buyer->name}.",
            [
                'order_id'    => $order->id,
                'product_id'  => $order->product_id,
                'buyer_id'    => $order->buyer_id,
                'farmer_id'   => $farmer->id,
                'quantity'    => $order->quantity,
                'total_price' => $order->total_price,
            ],
            '🛒',
            "/app/orders/{$order->id}"
        );

        return $farmerNotification;
    }

    public function orderConfirmed(User $buyer, $order): Notification
    {
        return $this->send(
            $buyer,
            'order_confirmed',
            'Order Confirmed! ✅',
            "Your order for {$order->product->name} has been confirmed by the farmer.",
            [
                'order_id'   => $order->id,
                'product_id' => $order->product_id,
                'farmer_id'  => $order->product->farmer_id,
            ],
            '✅',
            "/app/orders/{$order->id}"
        );
    }

    public function orderShipped(User $buyer, $order): Notification
    {
        return $this->send(
            $buyer,
            'order_shipped',
            'Order Shipped! 🚚',
            "Your order for {$order->product->name} has been shipped.",
            [
                'order_id'   => $order->id,
                'product_id' => $order->product_id,
            ],
            '🚚',
            "/app/orders/{$order->id}"
        );
    }

    public function orderDelivered(User $buyer, $order): Notification
    {
        return $this->send(
            $buyer,
            'order_delivered',
            'Order Delivered! 📦',
            "Your order for {$order->product->name} has been delivered. Please leave a review!",
            [
                'order_id'   => $order->id,
                'product_id' => $order->product_id,
            ],
            '📦',
            "/app/orders/{$order->id}"
        );
    }

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
                'order_id'   => $order->id,
                'product_id' => $order->product_id,
            ],
            '❌',
            "/app/orders/{$order->id}"
        );
    }

    // -----------------------------------------------------------------------
    // Farmer verification notifications
    // -----------------------------------------------------------------------

    public function farmerVerificationRequest(User $farmer): void
    {
        $this->sendToAdmins(
            'farmer_verification_request',
            'New Farmer Verification Request! 👨‍🌾',
            "{$farmer->name} has requested to become a verified farmer.",
            [
                'farmer_id'    => $farmer->id,
                'farmer_name'  => $farmer->name,
                'farmer_email' => $farmer->email,
            ],
            '👨‍🌾',
            '/app/admin/farmers/verification'
        );
    }

    public function farmerVerified(User $farmer): Notification
    {
        $farmerNotification = $this->send(
            $farmer,
            'farmer_verified',
            'Account Verified! 🎉',
            'Your farmer account has been verified. You can now start listing products.',
            [],
            '✅',
            '/app/dashboard'
        );

        $this->sendToAdmins(
            'farmer_verified',
            'Farmer Verified ✅',
            "{$farmer->name} has been verified as a farmer.",
            [
                'farmer_id'   => $farmer->id,
                'farmer_name' => $farmer->name,
            ],
            '✅',
            '/app/admin/farmers/verification'
        );

        return $farmerNotification;
    }

    public function farmerRejected(User $farmer, string $reason): Notification
    {
        $farmerNotification = $this->send(
            $farmer,
            'farmer_rejected',
            'Verification Rejected ❌',
            "Your farmer account verification was rejected. Reason: {$reason}",
            ['reason' => $reason],
            '❌',
            '/app/profile'
        );

        $this->sendToAdmins(
            'farmer_rejected',
            'Farmer Rejected ❌',
            "{$farmer->name}'s verification request was rejected. Reason: {$reason}",
            [
                'farmer_id'   => $farmer->id,
                'farmer_name' => $farmer->name,
                'reason'      => $reason,
            ],
            '❌',
            '/app/admin/farmers/verification'
        );

        return $farmerNotification;
    }

    // -----------------------------------------------------------------------
    // Product notifications
    // -----------------------------------------------------------------------

    public function newProductListed(array $buyers, $product): void
    {
        $this->sendToMany(
            $buyers,
            'new_product',
            'New Product Available! 🌾',
            "A new product has been listed: {$product->name} by {$product->farmer->name}.",
            [
                'product_id' => $product->id,
                'farmer_id'  => $product->farmer_id,
            ],
            '🌾',
            "/app/products/{$product->id}"
        );

        $this->sendToAdmins(
            'new_product',
            'New Product Listed 🌾',
            "{$product->farmer->name} has listed a new product: {$product->name}.",
            [
                'product_id'  => $product->id,
                'farmer_id'   => $product->farmer_id,
                'farmer_name' => $product->farmer->name,
            ],
            '🌾',
            '/app/admin/products'
        );
    }

    public function lowStock(User $farmer, $product): Notification
    {
        $farmerNotification = $this->send(
            $farmer,
            'low_stock',
            'Low Stock Alert! ⚠️',
            "Your product {$product->name} is running low. Only {$product->quantity} {$product->unit} remaining.",
            [
                'product_id' => $product->id,
                'quantity'   => $product->quantity,
            ],
            '⚠️',
            '/app/products'
        );

        $this->sendToAdmins(
            'low_stock',
            'Low Stock Alert ⚠️',
            "Product {$product->name} by {$farmer->name} is running low. {$product->quantity} {$product->unit} remaining.",
            [
                'product_id'  => $product->id,
                'farmer_id'   => $farmer->id,
                'farmer_name' => $farmer->name,
                'quantity'    => $product->quantity,
            ],
            '⚠️',
            '/app/admin/products'
        );

        return $farmerNotification;
    }

    // -----------------------------------------------------------------------
    // Review notifications
    // -----------------------------------------------------------------------

    public function newReview(User $farmer, $order, $review): Notification
    {
        $farmerNotification = $this->send(
            $farmer,
            'new_review',
            'New Review Received! ⭐',
            "{$order->buyer->name} left a {$review->rating}⭐ review for your product {$order->product->name}.",
            [
                'order_id'   => $order->id,
                'product_id' => $order->product_id,
                'rating'     => $review->rating,
            ],
            '⭐',
            "/app/orders/{$order->id}"
        );

        $this->sendToAdmins(
            'new_review',
            'New Review Posted ⭐',
            "{$order->buyer->name} left a {$review->rating}⭐ review for {$order->product->name}.",
            [
                'order_id'   => $order->id,
                'product_id' => $order->product_id,
                'farmer_id'  => $farmer->id,
                'rating'     => $review->rating,
            ],
            '⭐',
            "/app/orders/{$order->id}"
        );

        return $farmerNotification;
    }

    // -----------------------------------------------------------------------
    // User registration notifications
    // -----------------------------------------------------------------------

    public function userRegistered(User $newUser): void
    {
        $this->sendToAdmins(
            'user_registered',
            'New User Registered! 👤',
            "A new user has registered: {$newUser->name} ({$newUser->email}) as a {$newUser->role}.",
            [
                'user_id'    => $newUser->id,
                'user_name'  => $newUser->name,
                'user_email' => $newUser->email,
                'user_role'  => $newUser->role,
            ],
            '👤',
            '/app/admin/users'
        );
    }
}
