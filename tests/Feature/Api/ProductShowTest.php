<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_description(): void
    {
        $product = Product::factory()->create(['description' => 'Grown without pesticides.']);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.description', 'Grown without pesticides.');
    }

    public function test_show_returns_average_rating_from_reviews(): void
    {
        $product = Product::factory()->create();

        $ratings = [5, 3, 4];
        foreach ($ratings as $rating) {
            $buyer = User::factory()->create(['role' => 'buyer']);
            $order = Order::factory()->create([
                'product_id' => $product->id,
                'buyer_id' => $buyer->id,
                'status' => 'delivered',
            ]);
            Review::create([
                'order_id' => $order->id,
                'user_id' => $buyer->id,
                'rating' => $rating,
                'comment' => null,
            ]);
        }

        $expectedAverage = round(array_sum($ratings) / count($ratings), 1);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200);
        // Whole-number floats (e.g. 4.0) serialize as a JSON integer, so
        // assertJsonPath's strict type check isn't the right tool here —
        // compare numerically instead.
        $this->assertEquals($expectedAverage, (float) $response->json('data.avg_rating'));
    }

    public function test_show_returns_zero_average_rating_with_no_reviews(): void
    {
        $product = Product::factory()->create();

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200);
        $this->assertEquals(0.0, (float) $response->json('data.avg_rating'));
    }

    public function test_show_returns_actual_orders_count(): void
    {
        $product = Product::factory()->create();

        Order::factory()->count(3)->create(['product_id' => $product->id]);

        // A different product's orders must not leak into this count.
        $otherProduct = Product::factory()->create();
        Order::factory()->count(2)->create(['product_id' => $otherProduct->id]);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.orders_count', 3);

        $this->assertSame(3, $product->orders()->count());
    }
}
