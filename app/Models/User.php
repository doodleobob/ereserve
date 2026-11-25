<?php

namespace App\Models;

use App\Services\TwoFactorCodes;
use App\Jobs\SendNotificationSms;
use App\Support\PasswordResetSmsMessage;
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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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

    public function createdEquipmentCharges(): HasMany
    {
        return $this->hasMany(EquipmentCharge::class, 'created_by');
    }

    public function restrictedPermissions(): HasMany
    {
        return $this->hasMany(AdminPermissionRestriction::class);
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

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $notification = new ResetPassword($token);
        $notification->id = (string) Str::uuid();
        try {
            $this->notify($notification);
        } finally {
            try {
                $id = $notification->id;
                $this->notifications()->create([
                    'id' => $id,
                    'type' => ResetPassword::class,
                    'data' => ['event' => 'password_reset_delivery', 'sms' => ['status' => 'pending']],
                ]);
                DB::afterCommit(fn () => SendNotificationSms::dispatch(
                    $id, static::class, (string) $this->getKey(), PasswordResetSmsMessage::make($this, $token),
                )->onConnection('database')->onQueue(config('queue.connections.database.queue', 'default')));
            } catch (Throwable $exception) {
                Log::warning('Password reset SMS could not be queued.', [
                    'notification_id' => $notification->id,
                    'exception_type' => $exception::class,
                ]);
            }
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
