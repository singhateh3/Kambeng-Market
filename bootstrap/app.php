<?php

// bootstrap/app.php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\CorsMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register middleware aliases (for use in routes)
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'cors' => \App\Http\Middleware\CorsMiddleware::class,
        ]);
        
        // Add middleware to API group
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
        
        // Add middleware to Web group
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
        
        // Register global middleware (runs on every request)
        // $middleware->append(\App\Http\Middleware\TrustProxies::class);
        // $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
        
        // Remove default middleware if needed
        // $middleware->remove(\Illuminate\Foundation\Http\Middleware\ValidatePostSize::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Custom exception handling
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated. Please login first.',
                    'code' => 'UNAUTHENTICATED'
                ], 401);
            }
        });
        
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthorized. Admin access required.',
                    'code' => 'FORBIDDEN'
                ], 403);
            }
        });
    })
    ->create();