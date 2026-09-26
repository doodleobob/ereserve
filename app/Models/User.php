<?php

namespace App\Models;

use Database\Factories\UserFactory;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone_number', 'password', 'role', 'barangay'])]
#[Hidden(['password', 'remember_token', 'phone_number'])]
class User extends Authenticatable implements MustVerifyEmail
{
    public const DEACTIVATED_MESSAGE = 'Your account has been deactivated. Please contact your barangay administrator.';

    protected $attributes = ['is_active' => true];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function twoFactorEnabled(): bool
    {
        return $this->two_factor_method !== null;
    }

    protected function phoneNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => PhoneNumber::normalize($value));
    }

    public function maskedTwoFactorDestination(): string
    {
        [$local, $domain] = explode('@', $this->email, 2);

        return mb_substr($local, 0, 1).'*****@'.$domain;
    }
}
