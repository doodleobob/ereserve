<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyEmailRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('dashboard')
            : view('auth.verify-email', [
                'destination' => $request->session()->get('url.intended', route('dashboard')),
            ]);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'verified' => $request->user()->fresh()->hasVerifiedEmail(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function verify(VerifyEmailRequest $request): View
    {
        $alreadyVerified = $request->user()->hasVerifiedEmail();
        $request->fulfill();

        return view('auth.verification-success', [
            'alreadyVerified' => $alreadyVerified,
            'destination' => redirect()->intended(route('dashboard'))->getTargetUrl(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (TransportExceptionInterface $exception) {
            return back()->withErrors(['verification' => 'The verification email could not be sent. Please try again shortly.']);
        }

        return back()->with('status', 'verification-link-sent');
    }
}
