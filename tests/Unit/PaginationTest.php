<?php

namespace Tests\Unit;

use App\Support\Pagination;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Covers App\Support\Pagination::perPage() — extracted from four
 * controllers (Order/AdminOrder/SavedFarmer/NotificationController) during
 * the P1 rate-limit audit's follow-up pass, which also fixed the bug
 * covered by test_non_numeric_value_falls_back_to_the_default() below: the
 * original per-controller duplicated clamp used `$request->per_page ?? 20`,
 * and a non-numeric string like "abc" isn't null, so that fallback never
 * fired — PHP's (int) cast then silently turned it into 0, which clamped
 * down to 1 instead of preserving the intended default.
 */
class PaginationTest extends TestCase
{
    private function requestWith(?string $perPage): Request
    {
        return new Request($perPage === null ? [] : ['per_page' => $perPage]);
    }

    public function test_missing_value_falls_back_to_the_default(): void
    {
        $this->assertSame(20, Pagination::perPage($this->requestWith(null)));
    }

    public function test_blank_value_falls_back_to_the_default(): void
    {
        $this->assertSame(20, Pagination::perPage($this->requestWith('')));
    }

    public function test_non_numeric_value_falls_back_to_the_default(): void
    {
        $this->assertSame(20, Pagination::perPage($this->requestWith('abc')));
    }

    public function test_value_within_range_is_used_as_is(): void
    {
        $this->assertSame(50, Pagination::perPage($this->requestWith('50')));
    }

    public function test_value_above_max_is_clamped_to_the_max(): void
    {
        $this->assertSame(100, Pagination::perPage($this->requestWith('500')));
    }

    public function test_zero_is_clamped_to_one(): void
    {
        $this->assertSame(1, Pagination::perPage($this->requestWith('0')));
    }

    public function test_negative_value_is_clamped_to_one(): void
    {
        $this->assertSame(1, Pagination::perPage($this->requestWith('-5')));
    }

    public function test_decimal_value_is_truncated(): void
    {
        $this->assertSame(20, Pagination::perPage($this->requestWith('20.9')));
    }

    public function test_custom_default_and_max_are_respected(): void
    {
        $this->assertSame(10, Pagination::perPage($this->requestWith(null), default: 10, max: 30));
        $this->assertSame(30, Pagination::perPage($this->requestWith('999'), default: 10, max: 30));
    }
}
