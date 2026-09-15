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
use App\Models\Review;
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
     * Dashboard/farmer/public statistics are cached (see DashboardCache,
     * AdminDashboardController::statistics(), FarmerProfileController::
     * statistics(), and PublicController::statistics()). Registered here
     * as model events rather than scattered Cache::forget() calls in
     * every controller that touches an order/product/user/review — this
     * way every current and future write path (including the ModemPay
     * webhook, which updates orders directly) invalidates automatically.
     *
     * Public statistics (GET /public/statistics) reports products.active,
     * users.farmers, orders.total, and reviews.average_rating — i.e. a
     * subset of exactly what Order/Product/User already invalidate here,
     * plus reviews, which nothing previously listened for. forgetPublic()
     * is added to the existing Order/Product/User closures rather than a
     * separate set of listeners, and a Review listener is added purely
     * for forgetPublic() — scoped to the public cache only, since wiring
     * Review into forgetAdmin() as well (admin stats also reads review
     * data, but has never invalidated on it) is a pre-existing gap
     * unrelated to this endpoint and out of scope here.
     */
    protected function configureDashboardCacheInvalidation(): void
    {
        $forgetForOrder = function (Order $order): void {
            DashboardCache::forgetAdmin();
            DashboardCache::forgetFarmer($order->product?->farmer_id);
            DashboardCache::forgetPublic();
        };
        Order::saved($forgetForOrder);
        Order::deleted($forgetForOrder);

        $forgetForProduct = function (Product $product): void {
            DashboardCache::forgetAdmin();
            DashboardCache::forgetFarmer($product->farmer_id);
            DashboardCache::forgetPublic();
        };
        Product::saved($forgetForProduct);
        Product::deleted($forgetForProduct);

        // Admin stats include user/farmer-verification counts; farmer
        // statistics don't depend on User fields, so no per-farmer forget here.
        $forgetForUser = function (): void {
            DashboardCache::forgetAdmin();
            DashboardCache::forgetPublic();
        };
        User::saved($forgetForUser);
        User::deleted($forgetForUser);

        // Only public statistics reads review data (reviews.average_rating)
        // — admin/farmer dashboards don't, so no forgetAdmin()/forgetFarmer()
        // here.
        Review::saved(fn () => DashboardCache::forgetPublic());
        Review::deleted(fn () => DashboardCache::forgetPublic());
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

        // POST /products — authenticated only, so key on the user, same
        // reasoning as orders-create. This is the most expensive
        // authenticated write in the app: up to 5 Cloudinary uploads plus a
        // synchronous per-buyer notification fan-out (NotificationService::
        // newProductListed()). 10/minute is well above legitimate listing
        // pace but stops a script from hammering it.
        RateLimiter::for('products-create', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()->id);
        });

        // --------------------------------------------------------------
        // P1 hardening — everything below this line was added after the
        // P0 pass (rate-limit audit "remaining P1 work"). None of the
        // limiters above were touched.
        // --------------------------------------------------------------

        // PUT /user/profile, PUT /farmer/profile — can include an avatar
        // upload (Cloudinary), so keep it above trivial "fixed a typo"
        // editing pace but well below what a script hammering it would
        // produce.
        RateLimiter::for('profile-update', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()->id);
        });

        // POST /farmer/profile/avatar — a dedicated Cloudinary upload (plus
        // a delete of the old asset on replace), tighter than the general
        // profile-update limit since it's the more expensive of the two.
        RateLimiter::for('avatar-upload', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()->id);
        });

        // PUT/PATCH/DELETE /products/{id}, photo add/delete — cheaper than
        // creation (no notification fan-out) but still Cloudinary-touching
        // for the photo endpoints, so still tighter than a pure-DB write.
        RateLimiter::for('products-write', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()->id);
        });

        // Order-state actions a buyer/farmer takes on an existing order:
        // cancel, status update, report/dispute, buyer confirm. Generous
        // enough for legitimate retries/double-clicks; each individual
        // action is already independently guarded by its own status-
        // transition/ownership checks regardless of this limit.
        RateLimiter::for('order-actions', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()->id);
        });

        // POST /orders/{id}/review — a stopgap against the app-level-only
        // (no DB unique constraint — see the reviews table migration
        // above) duplicate-review check being raced by near-simultaneous
        // requests; tight since a legitimate buyer only ever reviews an
        // order once.
        RateLimiter::for('review-create', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()->id);
        });

        // Notification read/delete actions — cheap, ownership-scoped,
        // already low risk (see NotificationController); generous limit
        // purely as blast-radius insurance.
        RateLimiter::for('notifications-write', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()->id);
        });

        // Save/unsave a farmer — already idempotent and cheap
        // (SavedFarmerController); limiter is defense-in-depth only.
        RateLimiter::for('saved-farmers-write', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()->id);
        });

        // General admin writes (user management, farmer verification,
        // product/order moderation, disputes). Admin auth+role is the real
        // gate here — this is a brake on a compromised/scripted admin
        // session, not a primary control, so it's generous.
        RateLimiter::for('admin-write', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()->id);
        });

        // POST /admin/orders/{id}/retry-payout, /confirm-refund — real
        // money movement. Already the best-protected admin actions in the
        // app (ownership/policy checks, atomic conditional-claim UPDATEs,
        // an explicit ambiguous-outcome acknowledgment gate on retry — see
        // AdminOrderController) — this limiter is an extra margin against
        // a compromised admin session firing rapid repeated retries, not a
        // substitute for that existing protection.
        RateLimiter::for('admin-financial', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()->id);
        });

        // --------------------------------------------------------------
        // Phase 1 P0 fix — GET /public/statistics had no limiter at all
        // (audit finding). Public/unauthenticated, so IP-only, same
        // pattern as the other public-* limiters above.
        // --------------------------------------------------------------
        RateLimiter::for('public-statistics', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
