<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

use App\Services\NotificationService;

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
    }
}
