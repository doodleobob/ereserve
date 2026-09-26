<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoginDestination
{
    public static function redirect(Request $request): RedirectResponse
    {
        // Discard obsolete verification URLs saved before the link flow was removed.
        $intended = $request->session()->get('url.intended');
        if (is_string($intended) && str_starts_with($intended, route('verification.notice'))) {
            $request->session()->forget('url.intended');
        }

        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended(route('dashboard'));
    }
}
