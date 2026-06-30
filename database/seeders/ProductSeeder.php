<?php

// database/seeders/ProductSeeder.php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get an approved farmer
        $farmer = User::where('role', 'farmer')
            ->where('verification_status', 'approved')
            ->first();

        if (!$farmer) {
            $this->command->warn('⚠️ No approved farmer found. Creating a default farmer...');

            // Create a default farmer
            $farmer = User::create([
                'name' => 'Default Farmer',
                'email' => 'default@farmer.com',
                'phone' => '+2207000000',
                'location' => 'Brikama, West Coast Region',
                'role' => 'farmer',
                'password' => bcrypt('password123'),
                'verified_at' => now(),
                'verification_status' => 'approved',
                'email_verified_at' => now(),
            ]);

            $this->command->info('🌾 Default farmer created: default@farmer.com');
        }

        $categories = [
            'Vegetables' => ['Tomatoes', 'Onions', 'Peppers', 'Carrots', 'Cabbage', 'Lettuce', 'Spinach', 'Broccoli', 'Okra', 'Eggplant'],
            'Fruits' => ['Mangoes', 'Bananas', 'Oranges', 'Papayas', 'Watermelons', 'Pineapples', 'Avocados', 'Guavas', 'Passion Fruit'],
            'Grains' => ['Rice', 'Millet', 'Sorghum', 'Maize', 'Wheat', 'Barley'],
            'Herbs' => ['Mint', 'Basil', 'Coriander', 'Parsley', 'Thyme', 'Rosemary', 'Sage'],
            'Spices' => ['Chili', 'Ginger', 'Garlic', 'Turmeric', 'Cumin', 'Cinnamon', 'Cloves'],
            'Dairy' => ['Fresh Milk', 'Butter', 'Cheese', 'Yogurt', 'Cream', 'Ghee'],
            'Meat' => ['Beef', 'Lamb', 'Goat Meat', 'Pork', 'Sausages'],
            'Fish' => ['Tilapia', 'Catfish', 'Bonga', 'Shrimp', 'Barracuda', 'Mullet'],
            'Poultry' => ['Chicken', 'Duck', 'Guinea Fowl', 'Turkey', 'Quail'],
            'Eggs' => ['Chicken Eggs', 'Duck Eggs', 'Quail Eggs', 'Ostrich Eggs'],
            'Roots' => ['Cassava', 'Sweet Potatoes', 'Yams', 'Taro', 'Ginger Root', 'Turmeric Root'],
            'Legumes' => ['Beans', 'Groundnuts', 'Cowpeas', 'Soybeans', 'Lentils', 'Chickpeas'],
            'Groundnuts' => ['Raw Groundnuts', 'Roasted Groundnuts', 'Groundnut Oil', 'Groundnut Paste']
        ];

        $units = ['kg', 'bunch', 'pile', 'bag', 'piece', 'dozen'];

        $productCount = 0;

        foreach ($categories as $category => $items) {
            foreach ($items as $index => $item) {
                $harvestDate = now()->subDays(rand(0, 7));
                $expiryDate = now()->addDays(rand(3, 21));
                $quantity = rand(10, 200);
                $price = rand(50, 500);

                Product::create([
                    'farmer_id' => $farmer->id,
                    'name' => $item,
                    'category' => $category,
                    'variety' => $index % 2 === 0 ? 'Organic' : 'Local',
                    'quantity' => $quantity,
                    'unit' => $units[array_rand($units)],
                    'price' => $price,
                    'harvest_date' => $harvestDate,
                    'expiry_date' => $expiryDate,
                    'photos' => [],
                    'description' => "Fresh {$item} from The Gambia. Harvested " . $harvestDate->diffForHumans() . ".",
                    'status' => 'active',
                    'views_count' => rand(0, 150),
                ]);

                $productCount++;
            }
        }

        $this->command->info("📦 Created {$productCount} products for farmer: {$farmer->name}");
    }
}
