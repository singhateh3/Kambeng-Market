<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\DashboardCache;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register NotificationService as a singleton
        $this->app->singleton(NotificationService::class, function ($app) {
            return new NotificationService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        $this->configureRateLimiting();
        $this->configureDashboardCacheInvalidation();
    }

    /**
     * Dashboard/farmer statistics are cached (see DashboardCache and
     * AdminDashboardController::statistics() / FarmerProfileController::statistics()).
     * Registered here as model events rather than scattered Cache::forget()
     * calls in every controller that touches an order/product/user — this
     * way every current and future write path (including the ModemPay
     * webhook, which updates orders directly) invalidates automatically.
     */
    protected function configureDashboardCacheInvalidation(): void
    {
        $forgetForOrder = function (Order $order): void {
            DashboardCache::forgetAdmin();
            DashboardCache::forgetFarmer($order->product?->farmer_id);
        };
        Order::saved($forgetForOrder);
        Order::deleted($forgetForOrder);

        $forgetForProduct = function (Product $product): void {
            DashboardCache::forgetAdmin();
            DashboardCache::forgetFarmer($product->farmer_id);
        };
        Product::saved($forgetForProduct);
        Product::deleted($forgetForProduct);

        // Admin stats include user/farmer-verification counts; farmer
        // statistics don't depend on User fields, so no per-farmer forget here.
        User::saved(fn () => DashboardCache::forgetAdmin());
        User::deleted(fn () => DashboardCache::forgetAdmin());
    }

    /**
     * Rate limiters for the public auth endpoints (POST /login, /register,
     * /forgot-password). These sit outside auth:sanctum, so IP/email are
     * the only signals available to key on — same throttleKey() pattern
     * Breeze's own (unused-in-production) LoginRequest already uses, kept
     * for consistency.
     */
    protected function configureRateLimiting(): void
    {
        // Brute-force / credential-stuffing resistance: a tight limit per
        // email+IP stops repeated guesses against one account, and a
        // looser per-IP limit catches a single source sweeping many
        // different accounts.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Loose enough that a legitimate user fixing a validation error
        // isn't blocked, tight enough to stop scripted bulk sign-ups.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(5)->by($request->ip());
        });

        // Two concerns named explicitly in the audit: repeated requests
        // against one target email (harassment/enumeration confirmation),
        // and one source sweeping many different emails (enumeration).
        RateLimiter::for('forgot-password', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return [
                Limit::perHour(3)->by($key),
                Limit::perHour(10)->by($request->ip()),
            ];
        });

        // POST /auth/google, /auth/apple — no email field to key on (the
        // identity comes from a provider token, not user input), so this
        // is IP-only. Deliberately NOT reusing 'login' — its email-keyed
        // limit would key on an empty string for every social-login
        // request, lumping all users' attempts into one shared 5/minute
        // global bucket instead of limiting per source.
        RateLimiter::for('social-login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // POST /orders — authenticated only, so key on the user, not the
        // IP (shared connections/NAT shouldn't throttle each other).
        // 10/minute is well above legitimate checkout pace but stops a
        // buggy client or script from hammering order creation.
        RateLimiter::for('orders-create', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()->id);
        });

        // Public product browsing/detail (GET /products, /products/{id},
        // /products/categories, /products/regions, /products/featured) —
        // unauthenticated, so IP is the only signal. Generous enough for
        // normal browsing (search-as-you-type, pagination, product-detail
        // navigation) while still bounding scraping/abuse.
        RateLimiter::for('public-products', function (Request $request) {
            return Limit::perMinute(100)->by($request->ip());
        });

        // Public farmer profile (GET /farmers/{id}/profile) — unauthenticated.
        RateLimiter::for('public-farmer-profile', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // ModemPay webhook — signature-verified, not session-authenticated
        // (see ModemPayWebhookController), so this exists only to bound
        // abuse of the endpoint, not to gate legitimate traffic. ModemPay
        // retries up to 3 times per event on a non-200, and one deploy can
        // legitimately produce many events in a short window, so this is
        // deliberately generous and keyed by IP.
        RateLimiter::for('modempay-webhook', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip());
        });
    }
}
