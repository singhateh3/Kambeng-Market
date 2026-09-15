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
    private const ADMIN_CHARTS_KEY = 'dashboard:admin:charts';
    private const PUBLIC_KEY = 'dashboard:public:statistics';

    public static function adminKey(): string
    {
        return self::ADMIN_KEY;
    }

    public static function adminChartsKey(): string
    {
        return self::ADMIN_CHARTS_KEY;
    }

    public static function publicKey(): string
    {
        return self::PUBLIC_KEY;
    }

    public static function farmerKey(int $farmerId): string
    {
        return "dashboard:farmer:{$farmerId}:statistics";
    }

    /**
     * Forgets both admin-facing dashboard caches together — statistics()
     * and chartData() draw from the same underlying order/product/user
     * data, so every call site that needs one already needs the other.
     */
    public static function forgetAdmin(): void
    {
        Cache::forget(self::ADMIN_KEY);
        Cache::forget(self::ADMIN_CHARTS_KEY);
    }

    public static function forgetFarmer(?int $farmerId): void
    {
        if ($farmerId === null) {
            return;
        }

        Cache::forget(self::farmerKey($farmerId));
    }

    /**
     * Forgets the public homepage statistics cache (GET /public/statistics
     * — see PublicController::statistics()). Kept as its own key/forget
     * call rather than folded into forgetAdmin() — a public homepage stat
     * and the full admin dashboard have no reason to share one cache
     * entry, even though the model events that invalidate them overlap
     * (see AppServiceProvider::configureDashboardCacheInvalidation()).
     */
    public static function forgetPublic(): void
    {
        Cache::forget(self::PUBLIC_KEY);
    }
}
