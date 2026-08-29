<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardChartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_charts_endpoint_returns_monthly_revenue_grouped_correctly(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::factory()->create();

        // Two delivered orders in the current month, one in the previous month.
        Order::factory()->create([
            'product_id' => $product->id,
            'status' => 'delivered',
            'total_price' => 100,
            'created_at' => now()->startOfMonth()->addDays(1),
        ]);
        Order::factory()->create([
            'product_id' => $product->id,
            'status' => 'delivered',
            'total_price' => 50,
            'created_at' => now()->startOfMonth()->addDays(2),
        ]);
        Order::factory()->create([
            'product_id' => $product->id,
            'status' => 'delivered',
            'total_price' => 75,
            'created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(1),
        ]);
        // Not delivered — must be excluded from revenue.
        Order::factory()->create([
            'product_id' => $product->id,
            'status' => 'pending',
            'total_price' => 999,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard/charts');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['daily_orders', 'monthly_revenue', 'user_growth'],
            ]);

        $monthlyRevenue = $response->json('data.monthly_revenue');

        $currentMonthRow = collect($monthlyRevenue)->firstWhere('month', now()->month);
        $this->assertNotNull($currentMonthRow, 'Expected a row for the current month.');
        $this->assertSame(now()->year, $currentMonthRow['year']);
        $this->assertEquals(150.0, $currentMonthRow['revenue']);

        $previousMonth = now()->subMonthNoOverflow();
        $previousMonthRow = collect($monthlyRevenue)->first(
            fn ($row) => $row['month'] === $previousMonth->month && $row['year'] === $previousMonth->year
        );
        $this->assertNotNull($previousMonthRow, 'Expected a row for the previous month.');
        $this->assertEquals(75.0, $previousMonthRow['revenue']);
    }

    public function test_charts_endpoint_requires_admin(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        Sanctum::actingAs($buyer);

        $response = $this->getJson('/api/admin/dashboard/charts');

        $response->assertStatus(403);
    }
}
