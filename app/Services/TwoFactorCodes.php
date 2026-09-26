<?php

namespace App\Services;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\SecurityCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TwoFactorCodes
{
    public function context(User $user): string
    {
        return hash_hmac('sha256', json_encode([
            $user->getKey(), $user->password, $user->email, $user->two_factor_method,
            (string) $user->email_verified_at,
        ]), config('app.key'));
    }

    public function issue(User $user, string $purpose, string $binding): void
    {
        $verifyingEmail = $purpose === 'verify';
        if ($verifyingEmail && $user->hasVerifiedEmail()) {
            return;
        }
        if (! $verifyingEmail && ! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['two_factor' => 'Your email must be verified before requesting a security code.']);
        }

        // Never fall back to a log mailer, which would expose a security code.
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if ($transport !== 'smtp'
            && ! (app()->environment('testing') && $transport === 'array')) {
            throw ValidationException::withMessages(['two_factor' => 'Email security codes are temporarily unavailable.']);
        }

        $challenge = DB::transaction(function () use ($user, $purpose, $binding) {
            $fresh = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! hash_equals($this->context($fresh), $this->context($user))) {
                throw ValidationException::withMessages(['two_factor' => 'Your account changed. Please start again.']);
            }

            foreach (['otp-send-minute:'.$user->id => [1, 60], 'otp-send-window:'.$user->id => [5, 900]] as $key => [$limit, $seconds]) {
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    throw ValidationException::withMessages(['two_factor' => 'Please wait before requesting another code.']);
                }
            }
            RateLimiter::hit('otp-send-minute:'.$user->id, 60);
            RateLimiter::hit('otp-send-window:'.$user->id, 900);

            $previous = TwoFactorChallenge::where('user_id', $user->id)->where('purpose', $purpose)->first();
            do {
                $code = (string) random_int(100000, 999999);
            } while ($previous && Hash::check($code, $previous->code_hash));
            $previous?->delete();
            $challenge = TwoFactorChallenge::create([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'purpose' => $purpose,
                'method' => 'email', 'destination' => $fresh->email, 'code_hash' => Hash::make($code),
                'binding_hash' => hash('sha256', $binding), 'context_hash' => $this->context($fresh),
                'expires_at' => now()->addMinutes(5),
            ]);

            return [$challenge, $code];
        });

        [$record, $code] = $challenge;
        try {
            $user->notify(new SecurityCode($code, $purpose));
        } catch (\Throwable $exception) {
            // Delivery errors must not leak the provider's message, destination, or code.
            $record->delete();
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

            $verificationStateValid = $purpose === 'verify' ? ! $user->hasVerifiedEmail() : $user->hasVerifiedEmail();
            if (! $user->is_active || ! $verificationStateValid || $challenge->method !== 'email'
                || $challenge->expires_at->isPast() || $challenge->attempts >= 5
                || ! hash_equals($challenge->context_hash, $this->context($user))) {
                $challenge->delete();

                return null;
            }

            $key = 'otp-guesses:'.$userId;
            if (RateLimiter::tooManyAttempts($key, 10)) {
                return null;
            }
            RateLimiter::hit($key, 300);
            $challenge->increment('attempts');
            if (! Hash::check($code, $challenge->code_hash)) {
                if ($challenge->attempts >= 5) {
                    $challenge->delete();
                }

                return null;
            }

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
