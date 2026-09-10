<?php

// app/Support/Pagination.php
//
// Shared per_page-clamping logic for the four index() endpoints that had
// no cap at all before the P1 rate-limit audit (OrderController,
// AdminOrderController, SavedFarmerController, NotificationController) —
// extracted here once the same clamp expression started being duplicated
// a fourth time, and to fix a bug the initial per-controller duplication
// shared: a non-numeric per_page (e.g. "abc") isn't null, so `?? $default`
// never kicks in; PHP's (int) cast on it then silently produces 0, which
// clamped down to 1 instead of preserving the intended default.

namespace App\Support;

use Illuminate\Http\Request;

class Pagination
{
    /**
     * Resolve a request's per_page input, clamped to [1, $max]. Falls
     * back to $default when the value is missing, blank, or not actually
     * numeric — a garbage value is treated the same as no value at all,
     * rather than being cast to 0 and clamped down to 1.
     */
    public static function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        $value = $request->input('per_page');

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max(1, min((int) $value, $max));
    }
}
