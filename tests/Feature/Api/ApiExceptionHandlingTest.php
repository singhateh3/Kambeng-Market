<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the fix for the production security investigation: a nonexistent
 * resource on an api/* route was returning a raw HTML 500 (a Laravel
 * framework exception — internal file paths, class names, stack-trace
 * structure) instead of the app's intended clean JSON 404. Root cause,
 * confirmed by direct local reproduction during the investigation:
 * Illuminate\Foundation\Exceptions\Handler::prepareException() converts
 * ModelNotFoundException into Symfony's NotFoundHttpException BEFORE any
 * render() closure is checked, so the ModelNotFoundException closure in
 * bootstrap/app.php never actually ran for a real HTTP request. Fixed by
 * (a) adding a render() closure for NotFoundHttpException — the type
 * closures actually receive — and (b) a defensive catch-all Throwable
 * closure, registered last, so no api/*-or-JSON-expecting request can ever
 * fall through to Laravel's default (Blade-view-dependent) error
 * rendering, regardless of exception type.
 */
class ApiExceptionHandlingTest extends TestCase
{
    use RefreshDatabase;

    private function assertCleanJson404($response): void
    {
        $response->assertStatus(404);
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $response->assertJson([
            'success' => false,
            'message' => 'Resource not found',
            'code' => 'NOT_FOUND',
        ]);
        $body = $response->getContent();
        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('<!DOCTYPE', $body);
    }

    public function test_nonexistent_public_farmer_returns_clean_json_404(): void
    {
        $response = $this->getJson('/api/farmers/999999999/profile');

        $this->assertCleanJson404($response);
    }

    public function test_nonexistent_public_product_returns_clean_json_404(): void
    {
        $response = $this->getJson('/api/products/999999999');

        $this->assertCleanJson404($response);
    }

    public function test_nonexistent_admin_bound_resource_returns_clean_json_404(): void
    {
        // A representative authenticated, implicitly route-model-bound
        // endpoint (AdminUserController::show(User $user)) — exercises the
        // same ModelNotFoundException -> NotFoundHttpException conversion
        // via route-model-binding failure, a different trigger than the
        // manual firstOrFail() the farmer-profile bug used, same fix.
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/users/999999999');

        $this->assertCleanJson404($response);
    }

    public function test_unexpected_exception_on_an_api_request_returns_a_generic_json_500(): void
    {
        // A route registered only for this test, deliberately throwing a
        // distinctive exception carrying content that must never reach the
        // client — proves the defensive catch-all boundary itself, rather
        // than depending on finding (or fabricating) a real bug in an
        // existing endpoint to trigger an unhandled exception.
        Route::middleware('api')->get('/api/__test/unexpected-exception', function () {
            throw new \RuntimeException('sensitive-detail-that-must-never-leak-to-the-client');
        });

        $response = $this->getJson('/api/__test/unexpected-exception');

        $response->assertStatus(500);
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $response->assertJson([
            'success' => false,
            'code' => 'SERVER_ERROR',
        ]);

        $body = $response->getContent();
        $decoded = $response->json();

        // The response must be a safe, generic message only.
        $this->assertSame('An unexpected error occurred. Please try again later.', $decoded['message']);

        // None of the following — exception class, message, trace, file
        // path, or SQL — may appear anywhere in the response.
        $this->assertStringNotContainsString('sensitive-detail-that-must-never-leak-to-the-client', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString(__DIR__, $body);
        $this->assertStringNotContainsString('vendor/laravel', $body);
        $this->assertStringNotContainsString('.php', $body);
        $this->assertArrayNotHasKey('trace', $decoded);
        $this->assertArrayNotHasKey('file', $decoded);
        $this->assertArrayNotHasKey('line', $decoded);
        $this->assertArrayNotHasKey('exception', $decoded);
        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('<!DOCTYPE', $body);
    }

    /**
     * Confirms the fix didn't touch non-API/non-JSON exception rendering —
     * every render() closure in bootstrap/app.php (existing and new)
     * explicitly checks $request->is('api/*') || $request->expectsJson()
     * and returns null otherwise, letting Laravel's normal (non-JSON)
     * rendering proceed untouched for a plain browser-style request.
     */
    public function test_a_non_api_non_json_request_is_not_forced_into_the_json_error_shape(): void
    {
        Route::middleware('web')->get('/__test/unexpected-exception-web', function () {
            throw new \RuntimeException('web path exception');
        });

        // A plain GET with no Accept: application/json header and a path
        // outside api/* — expectsJson() is false and is('api/*') is false.
        $response = $this->get('/__test/unexpected-exception-web');

        $response->assertStatus(500);
        $this->assertNotSame('application/json', $response->headers->get('Content-Type'));
    }
}
