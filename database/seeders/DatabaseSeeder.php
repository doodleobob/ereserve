<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Barangays;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'user@example.com'],
            [
                'name' => 'Demo User',
                'password' => 'password',
                'role' => 'user',
                'barangay' => Barangays::DEFAULT,
            ],
        );

        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Demo Admin',
                'password' => 'password',
                'role' => 'admin',
                'barangay' => Barangays::DEFAULT,
            ],
        );

        User::updateOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'name' => 'Demo Super Admin',
                'password' => 'password',
                'role' => 'super_admin',
                'barangay' => Barangays::DEFAULT,
            ],
        );
    }
}
