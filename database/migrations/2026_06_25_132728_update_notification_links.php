<?php

// database/migrations/xxxx_xx_xx_update_notification_links.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Notification;

return new class extends Migration
{
    public function up(): void
    {
        // Update order links
        Notification::where('link', 'like', '/orders/%')
            ->chunk(100, function ($notifications) {
                foreach ($notifications as $notification) {
                    $notification->link = str_replace('/orders/', '/app/orders/', $notification->link);
                    $notification->save();
                }
            });

        // Update product links
        Notification::where('link', 'like', '/products/%')
            ->chunk(100, function ($notifications) {
                foreach ($notifications as $notification) {
                    $notification->link = str_replace('/products/', '/app/products/', $notification->link);
                    $notification->save();
                }
            });

        // Update admin links
        Notification::where('link', 'like', '/admin/%')
            ->chunk(100, function ($notifications) {
                foreach ($notifications as $notification) {
                    $notification->link = str_replace('/admin/', '/app/admin/', $notification->link);
                    $notification->save();
                }
            });

        // Update dashboard links
        Notification::where('link', '/dashboard')->chunk(100, function ($notifications) {
            foreach ($notifications as $notification) {
                $notification->link = '/app/dashboard';
                $notification->save();
            }
        });

        // Update profile links
        Notification::where('link', '/profile')->chunk(100, function ($notifications) {
            foreach ($notifications as $notification) {
                $notification->link = '/app/profile';
                $notification->save();
            }
        });

        // Update notification index links
        Notification::where('link', '/notifications')->chunk(100, function ($notifications) {
            foreach ($notifications as $notification) {
                $notification->link = '/app/notifications';
                $notification->save();
            }
        });
    }

    public function down(): void
    {
        // Revert links back
        Notification::where('link', 'like', '/app/orders/%')
            ->chunk(100, function ($notifications) {
                foreach ($notifications as $notification) {
                    $notification->link = str_replace('/app/orders/', '/orders/', $notification->link);
                    $notification->save();
                }
            });

        // ... revert other links similarly
    }
};
