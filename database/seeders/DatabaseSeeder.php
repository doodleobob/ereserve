<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Barangays;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $password = env('ERESERVE_SUPERADMIN_PASSWORD');

        if (empty($password)) {
            throw new \RuntimeException(
                'Super Admin password is not configured.'
            );
        }

        User::firstOrCreate(
            ['email' => 'ereservesystem@gmail.com'],
            [
                'name' => 'eReserve Super Admin',
                'username' => 'ereservesystem',
                'password' => Hash::make($password),
                'role' => 'super_admin',
                'barangay' => Barangays::DEFAULT,
            ]
        );
    }
}