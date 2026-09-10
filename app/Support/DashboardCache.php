<?php

// app/Support/DashboardCache.php
//
// Central place for the dashboard-statistics cache keys/TTL so the
// controllers that read them (AdminDashboardController, FarmerProfileController)
// and the model events that invalidate them (registered in
// AppServiceProvider::boot()) never drift apart. The default cache store
// is 'database' (see config/cache.php), which doesn't support tags, so
// invalidation is done by explicit key rather than Cache::tags().

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class DashboardCache
{
    /**
     * Dashboard statistics change with every order/product/verification
     * event but are read far more often than that — a few minutes of
     * staleness is an acceptable trade for not recomputing on every
     * dashboard load, and mutations proactively invalidate anyway.
     */
    public const TTL_SECONDS = 300;

    private const ADMIN_KEY = 'dashboard:admin:statistics';

    public static function adminKey(): string
    {
        return self::ADMIN_KEY;
    }

    public static function farmerKey(int $farmerId): string
    {
        return "dashboard:farmer:{$farmerId}:statistics";
    }

    public static function forgetAdmin(): void
    {
        Cache::forget(self::ADMIN_KEY);
    }

    public static function forgetFarmer(?int $farmerId): void
    {
        if ($farmerId === null) {
            return;
        }

        Cache::forget(self::farmerKey($farmerId));
    }
}
