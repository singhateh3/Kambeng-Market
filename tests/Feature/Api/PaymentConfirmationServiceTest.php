<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

/**
 * Covers Task 10's production blocker #3 — the webhook handler and the
 * reconciliation job used to independently duplicate the "mark order
 * paid" logic, meaning both racing for the same order (a webhook arriving
 * while reconciliation's HTTP call to ModemPay was still in flight) could
 * fire a duplicate orderPlaced() notification. Both now go through
 * PaymentConfirmationService, whose atomic conditional UPDATE guarantees
 * only one caller ever actually proceeds.
 */
class PaymentConfirmationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function awaitingPaymentOrder(): Order
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id, 'price' => 500]);

        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'awaiting_payment',
            'payment_method' => 'modempay',
            'payment_status' => 'pending',
            'total_price' => 1000,
            'commission_rate' => 0.03,
            'commission_amount' => 30,
            'farmer_net_amount' => 970,
            'modempay_intent_id' => 'int_test_' . uniqid(),
        ]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => 'charge',
            'amount' => 1000,
            'currency' => 'GMD',
            'commission_amount' => 30,
            'status' => 'pending',
            'modempay_reference' => $order->modempay_intent_id,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        return $order;
    }

    public function test_confirming_an_already_confirmed_order_is_a_safe_no_op(): void
    {
        $order = $this->awaitingPaymentOrder();
        $service = app(PaymentConfirmationService::class);

        // Simulates the webhook and reconciliation both reaching this call
        // for the same order — whichever the database serializes first
        // wins the atomic claim, the second gets 'already_processed'
        // rather than reprocessing.
        $first = $service->confirmPayment($order);
        $second = $service->confirmPayment($order->fresh());

        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);
        $this->assertSame('already_processed', $second['reason']);

        // Order transitioned exactly once, ledger updated exactly once.
        $fresh = $order->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame(1, PaymentTransaction::where('order_id', $order->id)->where('status', 'succeeded')->count());
    }

    public function test_confirmation_fires_the_farmer_notification_exactly_once(): void
    {
        $order = $this->awaitingPaymentOrder();
        $service = app(PaymentConfirmationService::class);

        $service->confirmPayment($order);
        $service->confirmPayment($order->fresh());
        $service->confirmPayment($order->fresh());

        $this->assertSame(
            1,
            \App\Models\Notification::where('user_id', $order->product->farmer_id)
                ->where('type', 'order_placed')
                ->count()
        );
    }

    public function test_reconciliation_job_does_not_reprocess_an_order_the_webhook_already_confirmed(): void
    {
        $order = $this->awaitingPaymentOrder();

        // Webhook processes it first.
        app(PaymentConfirmationService::class)->confirmPayment($order);

        // Reconciliation then also finds it "successful" via ModemPay's
        // verify endpoint (plausible if it started its HTTP call just
        // before the webhook landed) and tries to confirm it too.
        \Illuminate\Support\Facades\Http::fake([
            '*/v1/payments/verify*' => \Illuminate\Support\Facades\Http::response([
                'status' => true,
                'data' => ['status' => 'successful'],
            ]),
        ]);

        $this->artisan('orders:reconcile-modempay')->assertExitCode(0);

        // Still exactly one succeeded ledger row, one notification.
        $this->assertSame(1, PaymentTransaction::where('order_id', $order->id)->where('status', 'succeeded')->count());
        $this->assertSame(
            1,
            \App\Models\Notification::where('user_id', $order->product->farmer_id)->where('type', 'order_placed')->count()
        );
    }

    public function test_amount_mismatch_does_not_confirm_payment(): void
    {
        $order = $this->awaitingPaymentOrder();
        $service = app(PaymentConfirmationService::class);

        $result = $service->confirmPayment($order, 1.00); // order total is 1000

        $this->assertFalse($result['success']);
        $this->assertSame('amount_mismatch', $result['reason']);
        $this->assertSame('awaiting_payment', $order->fresh()->status);
    }
}
