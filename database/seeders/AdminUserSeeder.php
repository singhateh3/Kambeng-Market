<?php

// database/seeders/AdminUserSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\FarmerProfile;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if admin already exists
        $existingAdmin = User::where('email', 'admin@kambeng.com')->first();
        
        if ($existingAdmin) {
            $this->command->info('⚠️ Admin user already exists!');
            $this->command->info('📧 Email: admin@kambeng.com');
            $this->command->info('🔑 Password: password123');
            return;
        }

        // Create Admin User
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@kambeng.com',
            'phone' => '+2209999999',
            'location' => 'Banjul',
            'role' => 'admin',
            'avatar' => null,
            'password' => Hash::make('password123'),
            'verified_at' => now(),
            'verification_requested_at' => now(),
            'verification_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->command->info('✅ Admin user created successfully!');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('📧 Email: admin@kambeng.com');
        $this->command->info('🔑 Password: password123');
        $this->command->info('👤 Role: Admin');
        $this->command->info('✅ Status: Verified');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}