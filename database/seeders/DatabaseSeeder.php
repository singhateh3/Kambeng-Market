<?php

// database/seeders/DatabaseSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\FarmerProfile;
use App\Models\Product;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('🌾 Starting Kambeng Market Database Seeder...');

        // 1. Create Admin User
        $this->createAdminUser();

        // 2. Create Test Farmers
        $this->createTestFarmers();

        // 3. Create Test Buyers
        $this->createTestBuyers();

        // 4. Create Products
        $this->createTestProducts();

        // 5. Create Orders
        $this->createTestOrders();

        // 6. Create Reviews
        $this->createTestReviews();

        $this->command->info('✅ Database seeding completed successfully!');
        $this->command->info('');
        $this->command->info('📋 Login Credentials:');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('👤 Admin:     admin@kambeng.com / password123');
        $this->command->info('🌾 Farmer:    modou@farm.com / password123');
        $this->command->info('🌾 Farmer:    amina@farm.com / password123');
        $this->command->info('🛒 Buyer:     kai@hospitality.com / password123');
        $this->command->info('🛒 Buyer:     mama@catering.com / password123');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    /**
     * Create Admin User
     */
    private function createAdminUser(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@kambeng.com',
            'phone' => '+2209999999',
            'location' => 'Banjul',
            'role' => 'admin',
            'password' => Hash::make('password123'),
            'verified_at' => now(),
            'verification_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->command->info('👤 Admin user created: admin@kambeng.com');
    }

    /**
     * Create Test Farmers
     */
    private function createTestFarmers(): void
    {
        $farmers = [
            [
                'name' => 'Modou Jallow',
                'email' => 'modou@farm.com',
                'phone' => '+2207012345',
                'location' => 'Brikama',
                'farm_name' => 'Jallow Organic Farm',
                'farm_location' => 'Brikama, West Coast Region',
                'bio' => 'Growing organic vegetables since 2015. Specializing in tomatoes, onions, and leafy greens.',
                'verification_status' => 'approved',
            ],
            [
                'name' => 'Amina Saine',
                'email' => 'amina@farm.com',
                'phone' => '+2207123456',
                'location' => 'Farafenni',
                'farm_name' => 'Saine Family Farm',
                'farm_location' => 'Farafenni, North Bank Region',
                'bio' => 'Multi-generational farm producing rice, groundnuts, and fresh vegetables.',
                'verification_status' => 'pending',
            ],
            [
                'name' => 'Lamin Camara',
                'email' => 'lamin@farm.com',
                'phone' => '+2207234567',
                'location' => 'Banjul',
                'farm_name' => 'Camara Urban Farm',
                'farm_location' => 'Banjul, Banjul Region',
                'bio' => 'Urban farming pioneer. Growing fresh produce in the heart of Banjul.',
                'verification_status' => 'pending',
            ],
            [
                'name' => 'Fatou Bah',
                'email' => 'fatou@farm.com',
                'phone' => '+2207345678',
                'location' => 'Serekunda',
                'farm_name' => 'Bah Market Garden',
                'farm_location' => 'Serekunda, West Coast Region',
                'bio' => 'Specializing in fresh herbs, peppers, and exotic vegetables.',
                'verification_status' => 'rejected',
            ],
        ];

        foreach ($farmers as $farmerData) {
            $user = User::create([
                'name' => $farmerData['name'],
                'email' => $farmerData['email'],
                'phone' => $farmerData['phone'],
                'location' => $farmerData['location'],
                'role' => 'farmer',
                'password' => Hash::make('password123'),
                'verified_at' => $farmerData['verification_status'] === 'approved' ? now() : null,
                'verification_status' => $farmerData['verification_status'],
                'verification_requested_at' => $farmerData['verification_status'] !== 'approved' ? now() : null,
                'email_verified_at' => now(),
            ]);

            $user->farmerProfile()->create([
                'farm_name' => $farmerData['farm_name'],
                'farm_location' => $farmerData['farm_location'],
                'bio' => $farmerData['bio'],
                'id_verified' => $farmerData['verification_status'] === 'approved',
            ]);

            $this->command->info("🌾 Farmer created: {$farmerData['name']} ({$farmerData['farm_name']}) - Status: {$farmerData['verification_status']}");
        }
    }

    /**
     * Create Test Buyers
     */
    private function createTestBuyers(): void
    {
        $buyers = [
            [
                'name' => 'Kai Hospitality',
                'email' => 'kai@hospitality.com',
                'phone' => '+2207456789',
                'location' => 'Banjul',
                'company' => 'Kai Hotel & Restaurant',
            ],
            [
                'name' => 'Mama Africa Catering',
                'email' => 'mama@catering.com',
                'phone' => '+2207567890',
                'location' => 'Serekunda',
                'company' => 'Mama Africa Catering Services',
            ],
            [
                'name' => 'Senegambia Beach Resort',
                'email' => 'sbr@resort.com',
                'phone' => '+2207678901',
                'location' => 'Kololi',
                'company' => 'Senegambia Beach Resort',
            ],
        ];

        foreach ($buyers as $buyerData) {
            User::create([
                'name' => $buyerData['name'],
                'email' => $buyerData['email'],
                'phone' => $buyerData['phone'],
                'location' => $buyerData['location'],
                'role' => 'buyer',
                'password' => Hash::make('password123'),
                'verified_at' => now(),
                'verification_status' => 'approved',
                'email_verified_at' => now(),
            ]);

            $this->command->info("🛒 Buyer created: {$buyerData['name']} ({$buyerData['company']})");
        }
    }

    /**
     * Create Test Products
     */
    private function createTestProducts(): void
    {
        $farmers = User::where('role', 'farmer')->where('verification_status', 'approved')->get();
        
        if ($farmers->isEmpty()) {
            $this->command->warn('⚠️ No approved farmers found. Skipping product creation.');
            return;
        }

        $products = [
            [
                'name' => 'Fresh Tomatoes',
                'category' => 'Vegetables',
                'quantity' => 50,
                'unit' => 'kg',
                'price' => 150,
                'harvest_date' => now()->subDays(2),
                'expiry_date' => now()->addDays(5),
                'description' => 'Fresh, ripe tomatoes from our organic farm.',
            ],
            [
                'name' => 'Red Onions',
                'category' => 'Vegetables',
                'quantity' => 30,
                'unit' => 'kg',
                'price' => 100,
                'harvest_date' => now()->subDays(3),
                'expiry_date' => now()->addDays(10),
                'description' => 'Premium quality red onions.',
            ],
            [
                'name' => 'Green Leafy Vegetables',
                'category' => 'Vegetables',
                'quantity' => 20,
                'unit' => 'bunch',
                'price' => 50,
                'harvest_date' => now()->subDays(1),
                'expiry_date' => now()->addDays(3),
                'description' => 'Fresh leafy greens harvested daily.',
            ],
            [
                'name' => 'Local Rice',
                'category' => 'Grains',
                'quantity' => 100,
                'unit' => 'kg',
                'price' => 200,
                'harvest_date' => now()->subWeeks(2),
                'expiry_date' => now()->addMonths(6),
                'description' => 'Premium quality Gambian-grown rice.',
            ],
            [
                'name' => 'Hot Peppers',
                'category' => 'Vegetables',
                'quantity' => 15,
                'unit' => 'kg',
                'price' => 250,
                'harvest_date' => now()->subDays(1),
                'expiry_date' => now()->addDays(7),
                'description' => 'Fresh, spicy hot peppers.',
            ],
            [
                'name' => 'Sweet Potatoes',
                'category' => 'Vegetables',
                'quantity' => 25,
                'unit' => 'kg',
                'price' => 120,
                'harvest_date' => now()->subDays(5),
                'expiry_date' => now()->addWeeks(2),
                'description' => 'Locally grown sweet potatoes.',
            ],
            [
                'name' => 'Okra',
                'category' => 'Vegetables',
                'quantity' => 20,
                'unit' => 'kg',
                'price' => 180,
                'harvest_date' => now()->subDays(1),
                'expiry_date' => now()->addDays(4),
                'description' => 'Fresh okra from our garden.',
            ],
            [
                'name' => 'Garden Eggs',
                'category' => 'Vegetables',
                'quantity' => 30,
                'unit' => 'pile',
                'price' => 80,
                'harvest_date' => now()->subDays(2),
                'expiry_date' => now()->addDays(6),
                'description' => 'Fresh garden eggs.',
            ],
        ];

        foreach ($farmers as $index => $farmer) {
            // Each farmer gets 2-3 products
            $farmerProducts = array_slice($products, $index * 2, rand(2, 3));
            
            foreach ($farmerProducts as $productData) {
                Product::create([
                    'farmer_id' => $farmer->id,
                    'name' => $productData['name'],
                    'category' => $productData['category'],
                    'quantity' => $productData['quantity'],
                    'unit' => $productData['unit'],
                    'price' => $productData['price'],
                    'harvest_date' => $productData['harvest_date'],
                    'expiry_date' => $productData['expiry_date'],
                    'description' => $productData['description'],
                    'status' => 'active',
                    'views_count' => rand(10, 100),
                ]);
            }

            $this->command->info("📦 Products created for: {$farmer->name}");
        }
    }

    /**
     * Create Test Orders
     */
    private function createTestOrders(): void
    {
        $buyers = User::where('role', 'buyer')->get();
        $products = Product::where('status', 'active')->get();

        if ($buyers->isEmpty() || $products->isEmpty()) {
            $this->command->warn('⚠️ No buyers or products found. Skipping order creation.');
            return;
        }

        foreach ($buyers as $buyer) {
            $product = $products->random();
            
            $quantity = rand(1, 5);
            $statuses = ['pending', 'confirmed', 'shipped', 'delivered', 'delivered', 'delivered'];
            $status = $statuses[array_rand($statuses)];

            Order::create([
                'buyer_id' => $buyer->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'total_price' => $product->price * $quantity,
                'status' => $status,
                'delivery_method' => rand(0, 1) ? 'pickup' : 'farmer_delivery',
                'order_date' => now()->subDays(rand(1, 10)),
                'delivery_deadline' => now()->addDays(rand(1, 5)),
            ]);

            $this->command->info("📦 Order created for: {$buyer->name}");
        }
    }

    /**
     * Create Test Reviews
     */
    private function createTestReviews(): void
    {
        $deliveredOrders = Order::where('status', 'delivered')->take(10)->get();
        
        if ($deliveredOrders->isEmpty()) {
            $this->command->warn('⚠️ No delivered orders found. Skipping review creation.');
            return;
        }

        $comments = [
            'Excellent quality produce! Highly recommended.',
            'Fresh and timely delivery. Will order again.',
            'Good value for money. The vegetables were very fresh.',
            'Slightly expensive but quality is top-notch.',
            'Great service and communication. Love the local produce!',
            'The tomatoes were perfect. Best in Gambia!',
            'Fast delivery and great packaging.',
            'Quality products from a trusted farmer.',
            'Will definitely order again. Highly satisfied.',
            'Amazing farm-fresh vegetables!',
        ];

        foreach ($deliveredOrders as $order) {
            Review::create([
                'order_id' => $order->id,
                'user_id' => $order->buyer_id,
                'rating' => rand(3, 5),
                'comment' => $comments[array_rand($comments)],
            ]);

            $this->command->info("⭐ Review created for order #{$order->id}");
        }
    }
}