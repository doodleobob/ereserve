<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LoginDestination
{
    public static function redirect(Request $request): RedirectResponse
    {
        // Stale asset URLs (including /sw.js) must never become login destinations.
        // Preserve intended navigation only to local authenticated GET routes.
        $intended = $request->session()->get('url.intended');
        if (! is_string($intended)
            || str_starts_with($intended, route('verification.notice'))
            || ! self::isAuthenticatedPage($intended, $request)) {
            $request->session()->forget('url.intended');
        }

        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended(route('dashboard'));
    }

    private static function isAuthenticatedPage(string $url, Request $request): bool
    {
        if ($url === '' || str_contains($url, '\\')) {
            return false;
        }

        try {
            $destination = Request::create(url($url), 'GET');
            if ($destination->getSchemeAndHttpHost() !== $request->getSchemeAndHttpHost()) {
                return false;
            }

            $route = Route::getRoutes()->match($destination);

            return in_array('auth', $route->gatherMiddleware(), true)
                && $route->getName() !== 'verification.notice';
        } catch (NotFoundHttpException|MethodNotAllowedHttpException|InvalidArgumentException) {
            return false;
        }
    }
}
