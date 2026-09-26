<?php

namespace App\Http\Controllers;

use App\Services\TwoFactorCodes;
use App\Support\LoginDestination;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): Response|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('dashboard')
            : response()->view('auth.verify-email')->header('Cache-Control', 'no-store, private');
    }

    public function verify(Request $request, TwoFactorCodes $codes): RedirectResponse
    {
        if ($request->user()->fresh()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }
        $request->validate(['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);
        $user = $request->user();
        $verified = $codes->consume($user->id, 'verify', $codes->context($user), $request->string('code')->toString());
        if (! $verified) {
            return redirect()->route('verification.notice')
                ->withErrors(['code' => 'The code is incorrect, expired, or no longer usable. Request a new code if needed.']);
        }
        event(new Verified($verified));
        $user->refresh();
        $request->session()->regenerate();

        return LoginDestination::redirect($request)->with('status', 'Email verified successfully!');
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        $request->user()->sendEmailVerificationNotification();

        return redirect()->route('verification.notice')->with('status', 'verification-code-sent');
    }
}
