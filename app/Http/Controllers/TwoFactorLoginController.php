<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactorCodes;
use App\Support\LoginDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TwoFactorLoginController extends Controller
{
    public function show(Request $request, TwoFactorCodes $codes)
    {
        $user = $this->pendingUser($request, $codes);
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Please sign in again to request a security code.']);
        }

        return response()->view('auth.two-factor-challenge', ['user' => $user])
            ->header('Cache-Control', 'no-store, private');
    }

    public function verify(Request $request, TwoFactorCodes $codes): RedirectResponse
    {
        $request->validate(['code' => ['required', 'regex:/^[0-9]{6}$/']]);
        $user = $this->pendingUser($request, $codes);
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Your sign-in attempt expired. Please sign in again.']);
        }

        $verified = $codes->consume($user->id, 'login', $request->session()->get('two_factor.login.binding'), $request->string('code')->toString());
        if (! $verified) {
            return back()->withErrors(['code' => 'The code is incorrect, expired, or no longer usable. Request a new code if needed.']);
        }

        $request->session()->forget('two_factor.login');
        Auth::login($verified);
        $request->session()->regenerate();

        return LoginDestination::redirect($request);
    }

    public function resend(Request $request, TwoFactorCodes $codes): RedirectResponse
    {
        $user = $this->pendingUser($request, $codes);
        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->two_factor_method !== 'email') {
            throw ValidationException::withMessages(['two_factor' => 'Your security method is unavailable.']);
        }
        $codes->issue($user, 'login', $request->session()->get('two_factor.login.binding'));

        return back()->with('status', 'A new security code has been sent. Previous codes no longer work.');
    }

    public function cancel(Request $request, TwoFactorCodes $codes): RedirectResponse
    {
        $pending = $request->session()->get('two_factor.login');
        if ($pending) {
            $codes->cancel($pending['user_id'], 'login', $pending['binding']);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function pendingUser(Request $request, TwoFactorCodes $codes): ?User
    {
        $pending = $request->session()->get('two_factor.login');
        $user = $pending ? User::find($pending['user_id']) : null;
        if ($user && ! $user->is_active) {
            $request->session()->forget('two_factor.login');
            throw ValidationException::withMessages(['email' => User::DEACTIVATED_MESSAGE])
                ->redirectTo(route('login'));
        }

        if (! $pending || $pending['expires_at'] <= now()->timestamp || ! $user
            || ! $user->twoFactorEnabled() || ! hash_equals($pending['context'], $codes->context($user))) {
            $request->session()->forget('two_factor.login');

            return null;
        }

        return $user;
    }
}
