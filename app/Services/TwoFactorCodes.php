<?php

namespace App\Services;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Mail\Transports\GmailApiFailureLogger;
use App\Mail\Transports\GmailApiTransportException;
use App\Notifications\SecurityCode;
use App\Support\ReservationMailDiagnostics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TwoFactorCodes
{
    private const SEND_COOLDOWN_SECONDS = 60;

    private const SEND_WINDOW_SECONDS = 900;

    private const SEND_WINDOW_MAXIMUM = 5;

    private const CODE_LIFETIME_MINUTES = 5;

    private const INCORRECT_CODE_MAXIMUM = 5;

    private const INCORRECT_CODE_WINDOW_SECONDS = 900;

    private const LOCKOUT_SECONDS = 600;

    public function context(User $user): string
    {
        return hash_hmac('sha256', json_encode([
            $user->getKey(), $user->password, $user->email, $user->two_factor_method,
            (string) $user->email_verified_at,
        ]), config('app.key'));
    }

    public function resendAvailableIn(User $user): int
    {
        $seconds = 0;
        $limiters = [
            'otp-send-minute:'.$user->id => 1,
            'otp-send-window:'.$user->id => self::SEND_WINDOW_MAXIMUM,
        ];

        foreach ($limiters as $key => $maximum) {
            if (RateLimiter::tooManyAttempts($key, $maximum)) {
                $seconds = max($seconds, RateLimiter::availableIn($key));
            }
        }

        $lockoutKey = 'otp-lockout:'.$user->id;
        if (RateLimiter::tooManyAttempts($lockoutKey, 1)) {
            $seconds = max($seconds, RateLimiter::availableIn($lockoutKey));
        }

        return $seconds;
    }

    public function issue(User $user, string $purpose, string $binding): void
    {
        $verifyingEmail = $purpose === 'verify';
        if ($verifyingEmail && $user->hasVerifiedEmail()) {
            return;
        }
        // Never fall back to a log mailer, which would expose a security code.
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if (! in_array($transport, ['smtp', 'gmail_api'], true)
            && ! (app()->environment('testing') && $transport === 'array')) {
            throw ValidationException::withMessages(['two_factor' => 'Email security codes are temporarily unavailable.']);
        }

        $challenge = DB::transaction(function () use ($user, $purpose, $binding) {
            $fresh = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! hash_equals($this->context($fresh), $this->context($user))) {
                throw ValidationException::withMessages(['two_factor' => 'Your account changed. Please start again.']);
            }

            $lockoutKey = 'otp-lockout:'.$user->id;
            if (RateLimiter::tooManyAttempts($lockoutKey, 1)) {
                throw ValidationException::withMessages(['two_factor' => 'Too many incorrect codes. Please wait before requesting another code.']);
            }

            foreach ([
                'otp-send-minute:'.$user->id => [1, self::SEND_COOLDOWN_SECONDS],
                'otp-send-window:'.$user->id => [self::SEND_WINDOW_MAXIMUM, self::SEND_WINDOW_SECONDS],
            ] as $key => [$limit, $seconds]) {
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    throw ValidationException::withMessages(['two_factor' => 'Please wait before requesting another code.']);
                }
            }
            RateLimiter::hit('otp-send-minute:'.$user->id, self::SEND_COOLDOWN_SECONDS);
            RateLimiter::hit('otp-send-window:'.$user->id, self::SEND_WINDOW_SECONDS);

            $previous = TwoFactorChallenge::where('user_id', $user->id)->where('purpose', $purpose)->first();
            do {
                $code = (string) random_int(100000, 999999);
            } while ($previous && Hash::check($code, $previous->code_hash));
            $previous?->delete();
            $challenge = TwoFactorChallenge::create([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'purpose' => $purpose,
                'method' => 'email', 'destination' => $fresh->email, 'code_hash' => Hash::make($code),
                'binding_hash' => hash('sha256', $binding), 'context_hash' => $this->context($fresh),
                'expires_at' => now()->addMinutes(self::CODE_LIFETIME_MINUTES),
            ]);

            return [$challenge, $code];
        });

        [$record, $code] = $challenge;
        try {
            $user->notify(new SecurityCode($code, $purpose));
        } catch (\Throwable $exception) {
            // Delivery errors must not leak the provider's message, destination, or code.
            $record->delete();
            if ($exception instanceof GmailApiTransportException) {
                GmailApiFailureLogger::log($exception, $purpose, $record->id);
            } elseif ($verifyingEmail) {
                try {
                    Log::warning('Email verification notification failed.', [
                        'stage' => 'notification_send',
                        'exception_type' => $exception::class,
                        'failure_category' => ReservationMailDiagnostics::failureCategory($exception),
                        'purpose' => 'verify',
                        'correlation_id' => $record->id,
                    ]);
                } catch (\Throwable) {
                    // Diagnostic failures must not replace the existing validation error.
                }
            }
            throw ValidationException::withMessages(['two_factor' => 'The security code could not be sent. Please try again shortly.']);
        }
    }

    public function consume(int $userId, string $purpose, string $binding, #[\SensitiveParameter] string $code): ?User
    {
        return DB::transaction(function () use ($userId, $purpose, $binding, $code) {
            $user = User::query()->lockForUpdate()->find($userId);
            $challenge = TwoFactorChallenge::where('user_id', $userId)->where('purpose', $purpose)->lockForUpdate()->first();
            if (! $user || ! $challenge || ! hash_equals($challenge->binding_hash, hash('sha256', $binding))) {
                return null;
            }

            $verificationStateValid = $purpose !== 'verify' || ! $user->hasVerifiedEmail();
            if (! $user->is_active || ! $verificationStateValid || $challenge->method !== 'email'
                || $challenge->expires_at->isPast() || $challenge->attempts >= self::INCORRECT_CODE_MAXIMUM
                || ! hash_equals($challenge->context_hash, $this->context($user))) {
                $challenge->delete();

                return null;
            }

            $lockoutKey = 'otp-lockout:'.$userId;
            if (RateLimiter::tooManyAttempts($lockoutKey, 1)) {
                return null;
            }

            $challenge->increment('attempts');
            if (! Hash::check($code, $challenge->code_hash)) {
                $failedKey = 'otp-failed:'.$userId;
                RateLimiter::hit($failedKey, self::INCORRECT_CODE_WINDOW_SECONDS);
                if ($challenge->attempts >= self::INCORRECT_CODE_MAXIMUM) {
                    $challenge->delete();
                }
                if (RateLimiter::tooManyAttempts($failedKey, self::INCORRECT_CODE_MAXIMUM)) {
                    RateLimiter::clear($failedKey);
                    RateLimiter::hit($lockoutKey, self::LOCKOUT_SECONDS);
                    $challenge->delete();
                }

                return null;
            }

            RateLimiter::clear('otp-failed:'.$userId);

            if ($purpose === 'setup') {
                $user->two_factor_method = 'email';
                $user->save();
                TwoFactorChallenge::where('user_id', $userId)->delete();
            } elseif ($purpose === 'verify') {
                $user->markEmailAsVerified();
                $challenge->delete();
            } else {
                $challenge->delete();
            }

            return $user;
        });
    }

    public function cancel(int $userId, string $purpose, string $binding): void
    {
        TwoFactorChallenge::where('user_id', $userId)->where('purpose', $purpose)
            ->where('binding_hash', hash('sha256', $binding))->delete();
    }
}
