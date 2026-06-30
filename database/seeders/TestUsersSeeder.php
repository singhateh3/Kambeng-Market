<?php

// database/seeders/TestUsersSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\FarmerProfile;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Admin User (skip if already created)
        if (!User::where('email', 'admin@kambeng.com')->exists()) {
            $this->createAdmin();
        }

        // 2. Test Farmer (Approved)
        $this->createTestFarmer();

        // 3. Test Farmer (Pending)
        $this->createPendingFarmer();

        // 4. Test Farmer (Rejected)
        $this->createRejectedFarmer();

        // 5. Test Buyer
        $this->createTestBuyer();

        $this->command->info('');
        $this->command->info('📋 All test users created successfully!');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('👤 Admin:    admin@kambeng.com / password123');
        $this->command->info('🌾 Farmer:   farmer@test.com / password123 (Approved)');
        $this->command->info('🌾 Farmer:   pending@test.com / password123 (Pending)');
        $this->command->info('🌾 Farmer:   rejected@test.com / password123 (Rejected)');
        $this->command->info('🛒 Buyer:    buyer@test.com / password123');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    /**
     * Create Admin User
     */
    private function createAdmin(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@kambeng.com'],
            [
                'name' => 'Admin User',
                'phone' => '+2209999999',
                'location' => 'Banjul',
                'role' => 'admin',
                'password' => Hash::make('password123'),
                'verified_at' => now(),
                'verification_requested_at' => now(),
                'verification_status' => 'approved',
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('👤 Admin created: admin@kambeng.com');
    }

    /**
     * Create Test Farmer (Approved)
     */
    private function createTestFarmer(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'farmer@test.com'],
            [
                'name' => 'Test Farmer',
                'phone' => '+2207000000',
                'location' => 'Brikama',
                'role' => 'farmer',
                'password' => Hash::make('password123'),
                'verified_at' => now(),
                'verification_requested_at' => now(),
                'verification_status' => 'approved',
                'email_verified_at' => now(),
            ]
        );

        FarmerProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'farm_name' => 'Test Farm Approved',
                'farm_location' => 'Brikama, West Coast Region',
                'bio' => 'Approved test farm for development purposes.',
                'id_verified' => true,
            ]
        );

        $this->command->info('🌾 Farmer created (Approved): farmer@test.com');
    }

    /**
     * Create Test Farmer (Pending)
     */
    private function createPendingFarmer(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'pending@test.com'],
            [
                'name' => 'Pending Farmer',
                'phone' => '+2207000001',
                'location' => 'Farafenni',
                'role' => 'farmer',
                'password' => Hash::make('password123'),
                'verified_at' => null,
                'verification_requested_at' => now(),
                'verification_status' => 'pending',
                'email_verified_at' => now(),
            ]
        );

        FarmerProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'farm_name' => 'Pending Farm',
                'farm_location' => 'Farafenni, North Bank Region',
                'bio' => 'This farm is pending verification.',
                'id_verified' => false,
            ]
        );

        $this->command->info('🌾 Farmer created (Pending): pending@test.com');
    }

    /**
     * Create Test Farmer (Rejected)
     */
    private function createRejectedFarmer(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'rejected@test.com'],
            [
                'name' => 'Rejected Farmer',
                'phone' => '+2207000002',
                'location' => 'Serekunda',
                'role' => 'farmer',
                'password' => Hash::make('password123'),
                'verified_at' => null,
                'verification_requested_at' => now(),
                'verification_status' => 'rejected',
                'email_verified_at' => now(),
            ]
        );

        FarmerProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'farm_name' => 'Rejected Farm',
                'farm_location' => 'Serekunda, West Coast Region',
                'bio' => 'This farm was rejected.',
                'id_verified' => false,
            ]
        );

        $this->command->info('🌾 Farmer created (Rejected): rejected@test.com');
    }

    /**
     * Create Test Buyer
     */
    private function createTestBuyer(): void
    {
        User::updateOrCreate(
            ['email' => 'buyer@test.com'],
            [
                'name' => 'Test Buyer',
                'phone' => '+2207111111',
                'location' => 'Banjul',
                'role' => 'buyer',
                'password' => Hash::make('password123'),
                'verified_at' => now(),
                'verification_requested_at' => now(),
                'verification_status' => 'approved',
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('🛒 Buyer created: buyer@test.com');
    }
}
