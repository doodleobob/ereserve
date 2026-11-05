<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_super_admin_does_not_require_or_receive_email_verification(): void
    {
        $key = 'ERESERVE_SUPERADMIN_PASSWORD';
        $original = getenv($key);
        $testValue = 'Temporary-seeder-test-password-123!';
        putenv($key.'='.$testValue);
        $_ENV[$key] = $testValue;
        $_SERVER[$key] = $testValue;

        try {
            app(DatabaseSeeder::class)->run();
            $user = User::where('email', 'ereservesystem@gmail.com')->sole();

            $this->assertSame('super_admin', $user->role);
            $this->assertSame('ereservesystem', $user->username);
            $this->assertNull($user->email_verified_at);
            $this->assertGuest();

            app(DatabaseSeeder::class)->run();
            $this->assertNull($user->fresh()->email_verified_at);
        } finally {
            if ($original === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$original);
                $_ENV[$key] = $original;
                $_SERVER[$key] = $original;
            }
        }
    }
}
