<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public const SENT_MESSAGE = 'If an account exists for this email, a password reset link has been sent.';

    public function request(): Response
    {
        return response()->view('auth.forgot-password')->header('Cache-Control', 'no-store, private');
    }

    public function send(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email', 'max:255']]);

        // Match the existing security-email policy: never write secret links to logs.
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if ($transport !== 'smtp' && ! (app()->environment('testing') && $transport === 'array')) {
            return redirect()->route('password.request')->withErrors([
                'email' => 'Password reset emails are temporarily unavailable. Please try again later.',
            ])->onlyInput('email');
        }

        Password::sendResetLink($credentials, function (User $user, #[\SensitiveParameter] string $token): void {
            try {
                $user->sendPasswordResetNotification($token);
            } catch (\Throwable) {
                Password::deleteToken($user);
                // Do not disclose account existence or log the provider's secret-bearing error.
                Log::warning('Password reset email delivery failed.');

                return;
            }

            event(new PasswordResetLinkSent($user));
        });

        // Missing accounts and broker throttling deliberately receive the same response.
        return redirect()->route('password.request')->with('status', self::SENT_MESSAGE);
    }

    public function edit(Request $request, string $token): Response
    {
        return response()->view('auth.reset-password', [
            'token' => $token,
            'email' => is_string($request->query('email')) ? $request->query('email') : '',
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function update(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset($credentials, function (User $user, #[\SensitiveParameter] string $password): void {
            // The existing User password cast hashes the new password.
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['reset' => 'This password reset link is invalid or has expired. Please request a new link.']);
        }

        $request->session()->forget('two_factor.login');

        return redirect()->route('login')->with('status', 'Your password has been reset successfully. You can now log in.');
    }
}
