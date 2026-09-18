<?php

// app/Http/Middleware/EnsureAccountIsActive.php
//
// Phase 3B enforcement point for an already-issued Sanctum token whose
// user has since been deactivated (see UserDeactivationService). Must run
// AFTER auth:sanctum resolves the user — see routes/api.php, where this is
// appended into the same middleware array right after 'auth:sanctum',
// never registered as a global api-group middleware (that runs before
// route-specific middleware, so Auth::check() would never be true there).
//
// Throws the framework's own AuthenticationException rather than a
// bespoke response, so this flows through the existing render() closure
// in bootstrap/app.php and produces the exact same 401 JSON shape
// ({success:false, message:'Unauthenticated...', code:'UNAUTHENTICATED'})
// as any other authentication failure — which is what the frontend's
// axios interceptor already treats as an involuntary logout (clears
// localStorage + the TanStack Query cache, redirects to /login). No
// frontend change is required for this to work correctly.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->deactivated_at !== null) {
            throw new AuthenticationException();
        }

        return $next($request);
    }
}
