<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoginDestination
{
    public static function hasPendingVerificationLink(Request $request): bool
    {
        $intended = $request->session()->get('url.intended');

        return is_string($intended)
            && str_starts_with($intended, route('verification.notice').'/');
    }

    public static function redirect(Request $request): RedirectResponse
    {
        if (self::hasPendingVerificationLink($request)) {
            return redirect()->intended(route('dashboard'));
        }

        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended(route('dashboard'));
    }
}
