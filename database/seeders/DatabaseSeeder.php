<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Barangays;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $email = 'ereservesystem@gmail.com';
        $password = env('ERESERVE_SUPERADMIN_PASSWORD');

        if (empty($password)) {
            throw new RuntimeException(
                'Super Admin password is not configured in the environment.'
            );
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            if ($user->role !== 'super_admin') {
                throw new RuntimeException(
                    'The configured email belongs to a non-Super Admin account.'
                );
            }

            // Update only the existing Super Admin password.
            $user->forceFill([
                'password' => Hash::make($password),
            ])->save();

            // Verify email if necessary.
            if (!$user->hasVerifiedEmail()) {
                $user->forceFill([
                    'email_verified_at' => now(),
                ])->save();
            }

            return;
        }

        // Create the initial Super Admin account.
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