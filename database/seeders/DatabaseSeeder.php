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
        $email = 'ereservesystem@gmail.com';

        $user = User::where('email', $email)->first();

        if ($user) {
            // Only verify an existing Super Admin.
            // Do not change their password or other account details.
            if ($user->role !== 'super_admin') {
                throw new \RuntimeException(
                    'The configured email belongs to a non-Super Admin account.'
                );
            }

            if (!$user->hasVerifiedEmail()) {
                $user->forceFill([
                    'email_verified_at' => now(),
                ])->save();
            }

            return;
        }

        // Password is required only when creating a new account.
        $password = env('ERESERVE_SUPERADMIN_PASSWORD');

        if (empty($password)) {
            throw new \RuntimeException(
                'Super Admin password is not configured.'
            );
        }

        User::create([
            'name' => 'eReserve Super Admin',
            'username' => 'ereservesystem',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'super_admin',
            'barangay' => Barangays::DEFAULT,
            'email_verified_at' => now(),
        ]);
    }
}