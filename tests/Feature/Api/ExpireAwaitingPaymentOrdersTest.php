<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpireAwaitingPaymentOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function awaitingOrder(\Carbon\Carbon $createdAt): Order
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $product = Product::factory()->create();

        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'awaiting_payment',
            'payment_method' => 'modempay',
            'payment_status' => 'pending',
            'modempay_intent_id' => 'int_' . uniqid(),
            'created_at' => $createdAt,
        ]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => 'charge',
            'amount' => $order->total_price,
            'currency' => 'GMD',
            'status' => 'pending',
            'modempay_reference' => $order->modempay_intent_id,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        return $order;
    }

    public function test_expires_orders_past_the_configured_timeout(): void
    {
        config(['commission.awaiting_payment_timeout_minutes' => 30]);
        $stale = $this->awaitingOrder(now()->subMinutes(45));
        $fresh = $this->awaitingOrder(now()->subMinutes(5));

        $this->artisan('orders:expire-awaiting-payment')->assertExitCode(0);

        $staleFresh = $stale->fresh();
        $this->assertSame('cancelled', $staleFresh->status);
        $this->assertSame('expired', $staleFresh->payment_status);

        $this->assertSame('awaiting_payment', $fresh->fresh()->status);
    }

    public function test_expiring_marks_the_pending_charge_ledger_row_failed(): void
    {
        config(['commission.awaiting_payment_timeout_minutes' => 30]);
        $order = $this->awaitingOrder(now()->subMinutes(45));

        $this->artisan('orders:expire-awaiting-payment');

        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'type' => 'charge',
            'status' => 'failed',
        ]);
    }

    public function test_timeout_is_configurable(): void
    {
        config(['commission.awaiting_payment_timeout_minutes' => 10]);
        $order = $this->awaitingOrder(now()->subMinutes(15));

        $this->artisan('orders:expire-awaiting-payment');

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    /**
     * The expiry claim is a query-builder mass update
     * (Order::where(...)->update([...])), same as
     * PaymentConfirmationService — it never fires Order's 'saved' event,
     * so the command must invalidate the admin dashboard cache explicitly
     * (see the DashboardCache::forget*() call added after the claim).
     */
    public function test_expiring_an_order_invalidates_the_admin_dashboard_cache(): void
    {
        config(['commission.awaiting_payment_timeout_minutes' => 30]);
        $order = $this->awaitingOrder(now()->subMinutes(45));
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin);
        $before = $this->getJson('/api/admin/dashboard/statistics')
            ->assertStatus(200)
            ->json('data.orders.cancelled');

        $this->artisan('orders:expire-awaiting-payment')->assertExitCode(0);

        $after = $this->getJson('/api/admin/dashboard/statistics')
            ->assertStatus(200)
            ->json('data.orders.cancelled');

        $this->assertSame($before + 1, $after);
    }
}
