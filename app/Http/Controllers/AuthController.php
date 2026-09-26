<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactorCodes;
use App\Support\Barangays;
use App\Support\LoginDestination;
use App\Support\PhoneNumber;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AuthController extends Controller
{
    public function showLogin(Request $request): View
    {
        return view('auth.login');
    }

    public function showRegister(): View
    {
        return view('auth.register', [
            'barangays' => Barangays::ALL,
        ]);
    }

    public function login(Request $request, TwoFactorCodes $codes): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::validate($credentials)) {
            return back()
                ->withErrors(['email' => 'The provided credentials do not match our records.'])
                ->onlyInput('email');
        }

        $user = Auth::getLastAttempted();
        if (! $user->is_active) {
            $request->session()->forget('two_factor.login');

            return back()->withErrors(['email' => User::DEACTIVATED_MESSAGE])->onlyInput('email');
        }

        Auth::getProvider()->rehashPasswordIfRequired($user, $credentials);
        $request->session()->forget('two_factor.login');
        $request->session()->regenerate();

        if ($user->twoFactorEnabled()) {
            $binding = Str::random(64);
            $request->session()->put('two_factor.login', [
                'user_id' => $user->id, 'binding' => $binding,
                'context' => $codes->context($user), 'expires_at' => now()->addMinutes(10)->timestamp,
            ]);

            try {
                if ($user->two_factor_method !== 'email') {
                    throw ValidationException::withMessages(['two_factor' => 'Your security method is unavailable. Please contact support.']);
                }
                $codes->issue($user, 'login', $binding);
            } catch (ValidationException $exception) {
                return redirect()->route('two-factor.challenge')->withErrors($exception->errors());
            }

            return redirect()->route('two-factor.challenge')->with('status', 'A new security code has been sent.');
        }

        Auth::login($user);

        return LoginDestination::redirect($request);
    }

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => PhoneNumber::rules(),
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'barangay' => ['required', Rule::in(Barangays::ALL)],
        ], PhoneNumber::messages());

        $user = User::create($validated + [
            'role' => 'user',
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        try {
            event(new Registered($user));
        } catch (TransportExceptionInterface|ValidationException $exception) {
            return redirect()->route('verification.notice')
                ->withErrors(['verification' => 'Your account was created, but the verification email could not be sent. Please try resending it.']);
        }

        return redirect()->route('verification.notice');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
