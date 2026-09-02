<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The 3% commission is snapshotted once, at order creation, from
 * config('commission.rate') — and must never be recalculated from a
 * later/current rate for an existing order.
 */
class CommissionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function fakeModemPayCheckout(): void
    {
        Http::fake([
            '*/v1/payments' => Http::response([
                'status' => true,
                'data' => [
                    'payment_intent_id' => 'pi_' . uniqid(),
                    'intent_secret' => 'int_' . uniqid(),
                    'payment_link' => 'https://pay.modempay.com/checkout/int_' . uniqid(),
                    'status' => 'requires_payment_method',
                ],
            ], 201),
        ]);
    }

    public function test_order_snapshots_commission_from_current_config_rate(): void
    {
        config(['commission.rate' => 0.03]);
        $this->fakeModemPayCheckout();
        $buyer = User::factory()->create(['role' => 'buyer']);
        $product = Product::factory()->create(['price' => 1000, 'quantity' => 50]);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 1,
            'delivery_method' => 'pickup',
            'pickup_date' => now()->addDay()->toDateString(),
        ])->assertStatus(201);

        $order = Order::where('product_id', $product->id)->firstOrFail();
        $this->assertEquals(0.03, (float) $order->commission_rate);
        $this->assertEquals(30.00, (float) $order->commission_amount);
        $this->assertEquals(970.00, (float) $order->farmer_net_amount);
    }

    public function test_changing_config_rate_does_not_alter_an_existing_orders_snapshot(): void
    {
        config(['commission.rate' => 0.03]);
        $this->fakeModemPayCheckout();
        $buyer = User::factory()->create(['role' => 'buyer']);
        $product = Product::factory()->create(['price' => 1000, 'quantity' => 50]);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 1,
            'delivery_method' => 'pickup',
            'pickup_date' => now()->addDay()->toDateString(),
        ])->assertStatus(201);

        $order = Order::where('product_id', $product->id)->firstOrFail();
        $originalCommission = (float) $order->commission_amount;

        // The platform's rate changes after this order already existed.
        config(['commission.rate' => 0.05]);

        $order->refresh();
        $this->assertEquals($originalCommission, (float) $order->commission_amount);
        $this->assertEquals(30.00, (float) $order->commission_amount);
    }

    public function test_two_orders_placed_under_different_rates_each_keep_their_own_snapshot(): void
    {
        // Two separate POST /v1/payments calls in one test — fakeSequence()
        // is required here (not two Http::fake() calls) since a second
        // Http::fake() for the same URL pattern doesn't override the first.
        Http::fakeSequence('*/v1/payments')
            ->push(['status' => true, 'data' => ['payment_intent_id' => 'pi_a_' . uniqid(), 'intent_secret' => 'int_a_' . uniqid(), 'payment_link' => 'https://pay.modempay.com/a', 'status' => 'requires_payment_method']], 201)
            ->push(['status' => true, 'data' => ['payment_intent_id' => 'pi_b_' . uniqid(), 'intent_secret' => 'int_b_' . uniqid(), 'payment_link' => 'https://pay.modempay.com/b', 'status' => 'requires_payment_method']], 201);

        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        config(['commission.rate' => 0.03]);
        $productA = Product::factory()->create(['price' => 1000, 'quantity' => 50]);
        $this->postJson('/api/orders', [
            'product_id' => $productA->id, 'quantity' => 1,
            'delivery_method' => 'pickup', 'pickup_date' => now()->addDay()->toDateString(),
        ])->assertStatus(201);

        config(['commission.rate' => 0.05]);
        $productB = Product::factory()->create(['price' => 1000, 'quantity' => 50]);
        $this->postJson('/api/orders', [
            'product_id' => $productB->id, 'quantity' => 1,
            'delivery_method' => 'pickup', 'pickup_date' => now()->addDay()->toDateString(),
        ])->assertStatus(201);

        $orderA = Order::where('product_id', $productA->id)->firstOrFail();
        $orderB = Order::where('product_id', $productB->id)->firstOrFail();

        $this->assertEquals(30.00, (float) $orderA->commission_amount);
        $this->assertEquals(50.00, (float) $orderB->commission_amount);
    }
}
