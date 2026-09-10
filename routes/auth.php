<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

// This is Breeze's stock session-based scaffolding — live in production
// (routes/web.php requires this file unconditionally) but not what the SPA
// actually authenticates through (it uses /api/login, /api/register,
// /api/forgot-password — see routes/api.php). Reusing the SAME named
// limiters those API routes already use — AppServiceProvider::
// configureRateLimiting()'s 'register' and 'forgot-password' — rather than
// defining new ones: the limiter closures only read $request->ip()/
// ->input('email'), so they apply identically regardless of which route
// invokes them, and the values Kambeng wants here are exactly the values
// already defined there.
Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware(['guest', 'throttle:register'])
    ->name('register');

// Not throttled here deliberately — Breeze's own LoginRequest::authenticate()
// (app/Http/Requests/Auth/LoginRequest.php) already enforces its own
// 5-attempts-per-email+IP lockout via RateLimiter::tooManyAttempts()/hit()/
// clear(), independent of and in addition to route middleware. Adding a
// second, route-level limiter on top would be redundant.
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest')
    ->name('login');

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['guest', 'throttle:forgot-password'])
    ->name('password.email');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware('guest')
    ->name('password.store');

Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['auth', 'signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware(['auth', 'throttle:6,1'])
    ->name('verification.send');

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
