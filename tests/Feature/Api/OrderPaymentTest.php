<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers Task 6 (Cash on Delivery, now grandfathered/historical-only — see
 * OrderController::store()) and the ModemPay-only commission architecture
 * that replaced it. payment_status is never independently settable by any
 * request field; it only ever changes as a side effect of an order-status
 * transition the caller was already authorized to make, or a verified
 * ModemPay webhook (see Order::derivedPaymentStatus()).
 */
class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function buyerAndProduct(): array
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $product = Product::factory()->create(['quantity' => 50]);

        return [$buyer, $product];
    }

    private function orderPayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'quantity' => 2,
            'delivery_method' => 'pickup',
            'pickup_date' => now()->addDay()->toDateString(),
        ], $overrides);
    }

    private function fakeModemPayCheckout(): void
    {
        Http::fake([
            '*/v1/payments' => Http::response([
                'status' => true,
                'data' => [
                    'payment_intent_id' => 'pi_' . uniqid(),
                    'intent_secret' => 'int_' . uniqid(),
                    'payment_link' => 'https://pay.modempay.com/checkout/int_' . uniqid(),
                    'amount' => '1000',
                    'currency' => 'GMD',
                    'status' => 'requires_payment_method',
                ],
            ], 201),
        ]);
    }

    public function test_new_order_defaults_to_modempay_and_awaiting_payment(): void
    {
        $this->fakeModemPayCheckout();
        [$buyer, $product] = $this->buyerAndProduct();
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/orders', $this->orderPayload($product));

        $response->assertStatus(201)->assertJsonPath('data.status', 'awaiting_payment');

        $this->assertDatabaseHas('orders', [
            'product_id' => $product->id,
            'payment_method' => 'modempay',
            'payment_status' => 'pending',
            'status' => 'awaiting_payment',
        ]);
    }

    public function test_client_supplied_payment_method_is_ignored(): void
    {
        $this->fakeModemPayCheckout();
        [$buyer, $product] = $this->buyerAndProduct();
        Sanctum::actingAs($buyer);

        // No cash/COD option for new orders — even if a client tries to
        // smuggle a different payment_method in, it has no effect.
        $response = $this->postJson('/api/orders', $this->orderPayload($product, ['payment_method' => 'cod']));

        $response->assertStatus(201);
        $this->assertDatabaseHas('orders', [
            'product_id' => $product->id,
            'payment_method' => 'modempay',
        ]);
    }

    public function test_existing_orders_survive_migration_with_cod_pending_default(): void
    {
        [$buyer, $product] = $this->buyerAndProduct();

        // Inserted the way a pre-migration order would exist in the DB —
        // bypassing the model/factory entirely so this can't accidentally
        // pass due to an app-level default; only the migration's
        // column-level default is under test here.
        $id = DB::table('orders')->insertGetId([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'total_price' => 30,
            'status' => 'pending',
            'delivery_method' => 'pickup',
            'order_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = Order::find($id);
        $this->assertSame('cod', $order->payment_method);
        $this->assertSame('pending', $order->payment_status);

        // And it's still readable through the normal API, not just Eloquent.
        Sanctum::actingAs($buyer);
        $this->getJson("/api/orders/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id);
    }

    public function test_buyer_cannot_set_payment_status_on_order_creation(): void
    {
        $this->fakeModemPayCheckout();
        [$buyer, $product] = $this->buyerAndProduct();
        Sanctum::actingAs($buyer);

        // payment_status isn't even in the validated field list — smuggling
        // it into the request body must have no effect.
        $response = $this->postJson('/api/orders', $this->orderPayload($product, ['payment_status' => 'paid']));

        $response->assertStatus(201);
        $this->assertDatabaseHas('orders', [
            'product_id' => $product->id,
            'payment_status' => 'pending',
        ]);
    }

    public function test_buyer_cannot_manipulate_payment_status_via_status_endpoint(): void
    {
        [$buyer, $product] = $this->buyerAndProduct();
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'status' => 'pending']);
        Sanctum::actingAs($buyer);

        $response = $this->patchJson("/api/orders/{$order->id}/status", [
            'status' => 'delivered',
            'payment_status' => 'paid',
        ]);

        // Buyers have no access to this endpoint at all, regardless of payload.
        $response->assertStatus(403);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_farmer_cannot_arbitrarily_set_payment_status(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        $order = Order::factory()->create(['product_id' => $product->id, 'status' => 'pending']);
        Sanctum::actingAs($farmer);

        // A legitimate status change, with an unrelated payment_status
        // smuggled into the same request body.
        $response = $this->patchJson("/api/orders/{$order->id}/status", [
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]);

        $response->assertStatus(200);
        // The status change is honoured — it's a real, authorized transition...
        $this->assertSame('confirmed', $order->fresh()->status);
        // ...but the smuggled payment_status is not: 'confirmed' isn't a
        // transition the derivation rule touches at all.
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_delivered_cod_order_becomes_paid(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        $order = Order::factory()->create(['product_id' => $product->id, 'status' => 'shipped']);
        Sanctum::actingAs($farmer);

        $response = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'delivered']);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_admin_delivering_a_cod_order_also_marks_it_paid(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = Order::factory()->create(['status' => 'shipped']);
        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'delivered']);

        $response->assertStatus(200)
            ->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_cancelling_via_buyer_cancel_endpoint_marks_payment_cancelled(): void
    {
        [$buyer, $product] = $this->buyerAndProduct();
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'status' => 'pending']);
        Sanctum::actingAs($buyer);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(200);

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('cancelled', $fresh->payment_status);
    }

    public function test_cancelling_via_farmer_status_endpoint_marks_payment_cancelled(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        $order = Order::factory()->create(['product_id' => $product->id, 'status' => 'pending']);
        Sanctum::actingAs($farmer);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled'])->assertStatus(200);

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('cancelled', $fresh->payment_status);
    }

    public function test_cancelling_via_admin_status_endpoint_marks_payment_cancelled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = Order::factory()->create(['status' => 'confirmed']);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'cancelled'])->assertStatus(200);

        $this->assertSame('cancelled', $order->fresh()->payment_status);
    }

    public function test_confirmed_and_shipped_orders_remain_payment_pending(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        $order = Order::factory()->create(['product_id' => $product->id, 'status' => 'pending']);
        Sanctum::actingAs($farmer);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(200);
        $this->assertSame('pending', $order->fresh()->payment_status);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'shipped'])->assertStatus(200);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_invalid_status_transitions_are_still_rejected(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        $order = Order::factory()->create(['product_id' => $product->id, 'status' => 'pending']);
        Sanctum::actingAs($farmer);

        // pending -> shipped is not a valid direct transition; unchanged
        // from before this task.
        $response = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'shipped']);

        $response->assertStatus(422);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_delivered_order_has_no_further_valid_transitions_and_keeps_its_payment_status(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create(['farmer_id' => $farmer->id]);
        $order = Order::factory()->create([
            'product_id' => $product->id,
            'status' => 'delivered',
            'payment_status' => 'paid',
        ]);
        Sanctum::actingAs($farmer);

        $response = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled']);

        $response->assertStatus(422);
        // Rejected transition — payment_status must stay exactly as it was.
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_order_index_response_exposes_payment_fields(): void
    {
        [$buyer, $product] = $this->buyerAndProduct();
        Order::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id]);
        Sanctum::actingAs($buyer);

        $response = $this->getJson('/api/orders');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.payment_method', 'cod')
            ->assertJsonPath('data.0.payment_status', 'pending');
    }

    public function test_admin_order_resource_exposes_payment_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Order::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/orders');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.payment_method', 'cod')
            ->assertJsonPath('data.0.payment_status', 'pending')
            ->assertJsonPath('data.0.payment_status_label', 'Payment pending');
    }
}
