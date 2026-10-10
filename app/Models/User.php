<?php

namespace App\Models;

use App\Services\TwoFactorCodes;
use App\Support\PhoneNumber;
use App\Support\Username;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'username', 'email', 'phone_number', 'password', 'role', 'barangay'])]
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

    public function sendEmailVerificationNotification(): void
    {
        $codes = app(TwoFactorCodes::class);
        try {
            $codes->issue($this, 'verify', $codes->context($this));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['verification' => $exception->validator->errors()->first()]);
        }
    }

    protected function phoneNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => PhoneNumber::normalize($value));
    }

    protected function username(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Username::normalize($value));
    }

    public function maskedTwoFactorDestination(): string
    {
        [$local, $domain] = explode('@', $this->email, 2);

        return mb_substr($local, 0, 1).'*****@'.$domain;
    }
}
