<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        if ($request->session()->get('two_factor.setup.expires_at', 0) <= now()->timestamp) {
            $request->session()->forget('two_factor.setup');
        }

        return response()->view('profile', [
            'pending' => $request->session()->has('two_factor.setup'),
            'securityErrors' => $request->session()->get('errors', new ViewErrorBag)->getBag('security'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            if ($user->two_factor_method === 'email') {
                return back()->withErrors(['email' => 'Disable two-factor authentication in the Profile Security section before changing your verified email.']);
            }
            $user->email_verified_at = null;
            $user->save();

            try {
                $user->sendEmailVerificationNotification();
            } catch (TransportExceptionInterface $exception) {
                return redirect()->route('verification.notice')
                    ->withErrors(['verification' => 'Your email was updated, but the verification email could not be sent. Please try resending it.']);
            }

            return redirect()->route('verification.notice');
        }

        $user->save();

        return back()->with('profile_status', 'Profile information updated successfully.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($validated['current_password'], $request->user()->password)) {
            return back()
                ->withErrors(['current_password' => 'The current password is incorrect.'])
                ->onlyInput();
        }

        $request->user()->update([
            'password' => $validated['password'],
        ]);

        return back()->with('password_status', 'Password changed successfully.');
    }
}
