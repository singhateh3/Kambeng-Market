<?php

// tests/Feature/Api/ProductCreationNotificationTest.php
//
// Covers the P0 audit fix to ProductController::store()'s buyer
// notification fan-out: buyers are now selected by id only (not full
// User models) and notifications are bulk-inserted instead of created
// one row at a time. These tests pin the *behavior* (who gets notified,
// with what content, no duplicates, product creation still succeeds even
// if notification handling fails) rather than the exact query method
// used, so the implementation can still evolve later (e.g. queueing).

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCreationNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Fresh Tomatoes',
            'category' => 'Vegetables',
            'quantity' => 20,
            'unit' => 'kg',
            'price' => 15.50,
            'harvest_date' => now()->subDay()->toDateString(),
            'expiry_date' => now()->addDays(10)->toDateString(),
            'description' => 'Vine-ripened tomatoes, picked this morning.',
        ], $overrides);
    }

    public function test_product_creation_notifies_every_buyer_and_admin_exactly_once(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $buyers = User::factory()->count(5)->create(['role' => 'buyer']);
        $admin = User::factory()->create(['role' => 'admin']);
        // A non-buyer, non-admin role must NOT receive the buyer notification.
        $otherFarmer = User::factory()->create(['role' => 'farmer']);

        Sanctum::actingAs($farmer);

        $response = $this->postJson('/api/products', $this->validPayload());

        $response->assertStatus(201);
        $productId = $response->json('data.id');

        // Every buyer got exactly one 'new_product' notification.
        foreach ($buyers as $buyer) {
            $this->assertSame(
                1,
                Notification::where('user_id', $buyer->id)->where('type', 'new_product')->count(),
                "Buyer {$buyer->id} should receive exactly one new_product notification."
            );
        }

        // The admin got exactly one too (existing behavior, unchanged).
        $this->assertSame(
            1,
            Notification::where('user_id', $admin->id)->where('type', 'new_product')->count()
        );

        // Neither the listing farmer nor an unrelated farmer are notified.
        $this->assertSame(0, Notification::where('user_id', $farmer->id)->where('type', 'new_product')->count());
        $this->assertSame(0, Notification::where('user_id', $otherFarmer->id)->where('type', 'new_product')->count());

        // Total row count is exactly buyers + admin — no duplicates.
        $this->assertSame(
            $buyers->count() + 1,
            Notification::where('type', 'new_product')->count()
        );

        // Content/recipient semantics preserved.
        $buyerNotification = Notification::where('user_id', $buyers->first()->id)->where('type', 'new_product')->first();
        $this->assertSame('New Product Available! 🌾', $buyerNotification->title);
        $this->assertStringContainsString('Fresh Tomatoes', $buyerNotification->message);
        $this->assertSame("/app/products/{$productId}", $buyerNotification->link);
        $this->assertFalse($buyerNotification->is_read);
        $this->assertSame($productId, $buyerNotification->data['product_id']);
    }

    public function test_product_creation_succeeds_even_if_notification_dispatch_throws(): void
    {
        // Mirrors the existing "don't fail product creation if notification
        // fails" behavior in ProductController::store()'s inner try/catch.
        $this->app->bind(NotificationService::class, function () {
            return new class extends NotificationService {
                public function newProductListed(array $buyerIds, $product): void
                {
                    throw new \RuntimeException('simulated notification failure');
                }
            };
        });

        $farmer = User::factory()->create(['role' => 'farmer']);
        Sanctum::actingAs($farmer);

        $response = $this->postJson('/api/products', $this->validPayload());

        $response->assertStatus(201)->assertJsonPath('success', true);
        $this->assertDatabaseHas('products', ['name' => 'Fresh Tomatoes', 'farmer_id' => $farmer->id]);
    }

    public function test_buyer_selection_query_fetches_only_ids_and_notifications_are_bulk_inserted(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        User::factory()->count(5)->create(['role' => 'buyer']);

        Sanctum::actingAs($farmer);

        DB::enableQueryLog();
        $response = $this->postJson('/api/products', $this->validPayload());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(201);

        $buyerSelectQueries = array_values(array_filter($queries, function ($entry) {
            return str_contains($entry['query'], 'from "users"') && str_contains($entry['query'], '"role" = ?');
        }));
        $this->assertNotEmpty($buyerSelectQueries, 'Expected a query selecting buyers by role.');
        $this->assertStringNotContainsString(
            'select *',
            strtolower($buyerSelectQueries[0]['query']),
            'Buyer lookup should not select full User rows — only the id column is needed.'
        );

        $notificationInsertQueries = array_values(array_filter($queries, function ($entry) {
            return str_contains($entry['query'], 'insert into "notifications"');
        }));
        // 5 buyers + 1 admin notification: with per-row create() this would
        // be 6 separate inserts; batching the buyer fan-out collapses it to
        // far fewer (1 batched buyer insert + individual admin sends, since
        // there are no admins here, just the 1 batched buyer insert).
        $this->assertLessThan(
            5,
            count($notificationInsertQueries),
            'Buyer notifications should be bulk-inserted, not one INSERT per buyer.'
        );
    }

    public function test_only_farmers_can_create_products(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/products', $this->validPayload())->assertStatus(403);
    }
}
