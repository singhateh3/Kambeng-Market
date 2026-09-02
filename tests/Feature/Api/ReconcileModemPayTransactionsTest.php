<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Safety net against a lost charge.succeeded webhook — verified directly
 * against ModemPay's confirmed GET /v1/payments/verify endpoint.
 */
class ReconcileModemPayTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private function awaitingOrder(): Order
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);

        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'awaiting_payment',
            'payment_method' => 'modempay',
            'payment_status' => 'pending',
            'modempay_intent_id' => 'pi_' . uniqid(),
            'modempay_intent_secret' => 'int_' . uniqid(),
            'created_at' => now()->subMinutes(10),
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

    public function test_recovers_an_order_whose_webhook_was_lost(): void
    {
        $order = $this->awaitingOrder();
        Http::fake([
            '*/v1/payments/verify*' => Http::response([
                'status' => true,
                'data' => ['intent_secret' => $order->modempay_intent_secret, 'status' => 'successful'],
            ]),
        ]);

        $this->artisan('orders:reconcile-modempay')->assertExitCode(0);

        $fresh = $order->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertNotNull($fresh->payment_confirmed_at);
    }

    public function test_leaves_a_genuinely_still_pending_order_untouched(): void
    {
        $order = $this->awaitingOrder();
        Http::fake([
            '*/v1/payments/verify*' => Http::response([
                'status' => true,
                'data' => ['intent_secret' => $order->modempay_intent_secret, 'status' => 'processing'],
            ]),
        ]);

        $this->artisan('orders:reconcile-modempay');

        $this->assertSame('awaiting_payment', $order->fresh()->status);
    }

    public function test_ignores_orders_younger_than_the_grace_period(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $product = Product::factory()->create();
        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'awaiting_payment',
            'payment_method' => 'modempay',
            'modempay_intent_id' => 'pi_' . uniqid(),
            'modempay_intent_secret' => 'int_' . uniqid(),
            'created_at' => now(), // just created — a webhook hasn't had time to arrive yet
        ]);

        Http::fake(['*/v1/payments/verify*' => Http::response(['data' => ['status' => 'successful']])]);

        $this->artisan('orders:reconcile-modempay');

        $this->assertSame('awaiting_payment', $order->fresh()->status);
    }
}
