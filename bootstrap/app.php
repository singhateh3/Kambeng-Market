<?php

// bootstrap/app.php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\EnsureAccountIsActive;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'account.active' => EnsureAccountIsActive::class,
        ]);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Custom exception handling for API routes
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Please login first.',
                    'code' => 'UNAUTHENTICATED'
                ], 401);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.',
                    'code' => 'FORBIDDEN'
                ], 403);
            }
        });

        // Handle validation exceptions for API
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // Handle model not found exceptions for API. Kept even though it
        // never actually fires for a real HTTP request (see the render()
        // closure below) — Laravel's own Handler::prepareException()
        // converts ModelNotFoundException into NotFoundHttpException
        // BEFORE any render() closure is checked, so a closure type-hinted
        // for the original ModelNotFoundException class is unreachable via
        // the normal request lifecycle. Left in place as harmless,
        // self-documenting intent (and a defensive no-op for the unlikely
        // case something calls the handler directly with the original
        // exception type, bypassing prepareException()).
        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found',
                    'code' => 'NOT_FOUND'
                ], 404);
            }
        });

        // This is the exception type render() closures actually receive
        // for a not-found lookup — see the comment above. Also covers a
        // genuinely nonexistent route/URL, and any other exception Laravel
        // itself maps to a 404 (RecordNotFoundException, RecordsNotFoundException,
        // BackedEnumCaseNotFoundException — see Handler::prepareException()).
        // Same response shape as the ModelNotFoundException closure above,
        // since it's the same "this resource doesn't exist" case.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found',
                    'code' => 'NOT_FOUND'
                ], 404);
            }
        });

        // Handle rate-limited requests for API — thrown by the throttle
        // middleware on /login, /register, /forgot-password.
        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many attempts. Please try again later.',
                    'code' => 'TOO_MANY_ATTEMPTS',
                ], 429, $e->getHeaders());
            }
        });

        // Defensive catch-all, deliberately registered LAST so every more
        // specific closure above still gets first refusal (Handler::
        // renderViaCallbacks() checks registered render() closures in
        // registration order and stops at the first one that returns a
        // non-null response). Exists so an api/*-or-JSON-expecting request
        // can never fall through to Laravel's default exception rendering
        // — which, for this API-only app (no config/view.php — see that
        // migration/config decision), can itself fail while trying to
        // compile a Blade error view, surfacing a raw framework exception
        // (internal file paths, class names, stack trace) publicly instead
        // of a safe response. Deliberately generic: never includes the
        // exception's own class, message, trace, or any config/env value.
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'An unexpected error occurred. Please try again later.',
                    'code' => 'SERVER_ERROR',
                ], 500);
            }
        });
    })
    ->create();
