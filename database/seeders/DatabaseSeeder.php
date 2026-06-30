<?php

// database/seeders/DatabaseSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('🌾 Starting Kambeng Market Database Seeder...');
        $this->command->info('');

        // Run seeders in the correct order (avoid foreign key constraint issues)
        $this->call([
            AdminUserSeeder::class,
            TestUsersSeeder::class,
            ProductSeeder::class,
            // Add other seeders here if needed
        ]);

        $this->command->info('');
        $this->command->info('✅ Database seeding completed successfully!');
        $this->command->info('');
        $this->command->info('📋 Login Credentials:');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('👤 Admin:     admin@kambeng.com / password123');
        $this->command->info('🌾 Farmer:    farmer@test.com / password123 (Approved)');
        $this->command->info('🌾 Farmer:    pending@test.com / password123 (Pending)');
        $this->command->info('🌾 Farmer:    rejected@test.com / password123 (Rejected)');
        $this->command->info('🛒 Buyer:     buyer@test.com / password123');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}
