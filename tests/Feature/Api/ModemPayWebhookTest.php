<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the ModemPay webhook handler: signature verification (HMAC-SHA512,
 * confirmed verbatim against ModemPay's docs this session — see
 * ModemPayClient::verifyWebhookSignature()), dedup via WebhookEvent, and
 * every handled event type.
 */
class ModemPayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function sign(string $body): string
    {
        return hash_hmac('sha512', $body, config('services.modempay.webhook_secret'));
    }

    private function postWebhook(array $body, ?string $signatureOverride = null)
    {
        $raw = json_encode($body);
        $signature = $signatureOverride ?? $this->sign($raw);

        return $this->call('POST', '/api/webhooks/modempay', [], [], [], [
            'HTTP_x-modem-signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    private function awaitingPaymentOrder(array $overrides = []): Order
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id, 'price' => 500]);

        $order = Order::factory()->create(array_merge([
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
        ], $overrides));

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

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        $order = $this->awaitingPaymentOrder();

        $response = $this->postWebhook([
            'event' => 'charge.succeeded',
            'payload' => ['id' => $order->modempay_intent_id, 'amount' => 1000],
        ], 'not-the-real-signature');

        $response->assertStatus(400);
        $this->assertSame('awaiting_payment', $order->fresh()->status);
    }

    public function test_webhook_with_missing_signature_is_rejected(): void
    {
        $raw = json_encode(['event' => 'charge.succeeded', 'payload' => []]);
        $response = $this->call('POST', '/api/webhooks/modempay', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw);

        $response->assertStatus(400);
    }

    public function test_charge_succeeded_moves_order_to_pending_and_marks_paid(): void
    {
        $order = $this->awaitingPaymentOrder();

        $response = $this->postWebhook([
            'event' => 'charge.succeeded',
            'payload' => ['id' => $order->modempay_intent_id, 'amount' => 1000],
        ]);

        $response->assertStatus(200);
        $fresh = $order->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertNotNull($fresh->payment_confirmed_at);

        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'type' => 'charge',
            'status' => 'succeeded',
        ]);
    }

    public function test_charge_succeeded_with_mismatched_amount_does_not_mark_paid(): void
    {
        $order = $this->awaitingPaymentOrder();

        $this->postWebhook([
            'event' => 'charge.succeeded',
            'payload' => ['id' => $order->modempay_intent_id, 'amount' => 1], // wrong amount
        ])->assertStatus(200); // still acknowledged, just not acted on

        $this->assertSame('awaiting_payment', $order->fresh()->status);
    }

    public function test_duplicate_webhook_delivery_does_not_reprocess(): void
    {
        $order = $this->awaitingPaymentOrder();
        $body = ['event' => 'charge.succeeded', 'payload' => ['id' => $order->modempay_intent_id, 'amount' => 1000]];

        $this->postWebhook($body)->assertStatus(200);
        $firstConfirmedAt = $order->fresh()->payment_confirmed_at;

        // Same event delivered again (ModemPay retries up to 3x on non-200,
        // and may simply redeliver) — must not reprocess.
        sleep(1);
        $this->postWebhook($body)->assertStatus(200);

        $this->assertSame($firstConfirmedAt->toIso8601String(), $order->fresh()->payment_confirmed_at->toIso8601String());
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_charge_expired_cancels_the_order(): void
    {
        $order = $this->awaitingPaymentOrder();

        $this->postWebhook([
            'event' => 'payment_intent.expired',
            'payload' => ['id' => $order->modempay_intent_id],
        ])->assertStatus(200);

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('expired', $fresh->payment_status);
    }

    public function test_charge_cancelled_cancels_the_order_as_failed(): void
    {
        $order = $this->awaitingPaymentOrder();

        $this->postWebhook([
            'event' => 'charge.cancelled',
            'payload' => ['id' => $order->modempay_intent_id],
        ])->assertStatus(200);

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('failed', $fresh->payment_status);
    }

    public function test_unrecognized_event_type_is_acknowledged_without_error(): void
    {
        $response = $this->postWebhook([
            'event' => 'customer.created',
            'payload' => ['id' => 'cus_123'],
        ]);

        $response->assertStatus(200);
    }

    public function test_webhook_for_unknown_order_does_not_error(): void
    {
        $response = $this->postWebhook([
            'event' => 'charge.succeeded',
            'payload' => ['id' => 'int_does_not_exist', 'amount' => 1000],
        ]);

        $response->assertStatus(200);
    }

    public function test_transfer_succeeded_marks_payout_paid(): void
    {
        $order = $this->awaitingPaymentOrder([
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payout_status' => 'released',
            'modempay_transfer_id' => 'tr_test_123',
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => 'payout',
            'amount' => 970,
            'currency' => 'GMD',
            'status' => 'pending',
            'modempay_reference' => 'tr_test_123',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->postWebhook([
            'event' => 'transfer.succeeded',
            'payload' => ['id' => 'tr_test_123'],
        ])->assertStatus(200);

        $this->assertSame('paid', $order->fresh()->payout_status);
        $this->assertDatabaseHas('payment_transactions', [
            'modempay_reference' => 'tr_test_123',
            'status' => 'succeeded',
        ]);
    }

    public function test_transfer_failed_marks_payout_failed(): void
    {
        $order = $this->awaitingPaymentOrder([
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payout_status' => 'released',
            'modempay_transfer_id' => 'tr_test_456',
        ]);

        $this->postWebhook([
            'event' => 'transfer.failed',
            'payload' => ['id' => 'tr_test_456'],
        ])->assertStatus(200);

        $this->assertSame('failed', $order->fresh()->payout_status);
    }
}
