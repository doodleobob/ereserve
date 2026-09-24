<?php

namespace App\Http\Controllers;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Services\TwoFactorCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SecurityController extends Controller
{
    public function setup(Request $request, TwoFactorCodes $codes): RedirectResponse
    {
        $request->validateWithBag('security', [
            'current_password' => ['required', 'current_password'],
        ]);
        $user = $request->user();
        if ($user->twoFactorEnabled()) {
            return redirect()->to(route('profile.edit').'#security')->with('security_status', 'Two-Factor Authentication is already enabled.');
        }
        $binding = Str::random(64);
        $this->sendSetupCode($codes, $user, $binding);
        $request->session()->put('two_factor.setup', [
            'binding' => $binding, 'user_id' => $user->id, 'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()->to(route('profile.edit').'#security')->with('security_status', 'We sent a 6-digit security code to your verified email.');
    }

    public function confirm(Request $request, TwoFactorCodes $codes): RedirectResponse
    {
        $request->validateWithBag('security', ['code' => ['required', 'regex:/^[0-9]{6}$/']]);
        $pending = $request->session()->get('two_factor.setup');
        if (! $pending || $pending['expires_at'] <= now()->timestamp || $pending['user_id'] !== $request->user()->id
            || ! $codes->consume($request->user()->id, 'setup', $pending['binding'], $request->string('code')->toString())) {
            return redirect()->to(route('profile.edit').'#security')->withErrors(['code' => 'The code is incorrect, expired, or no longer usable. Start setup again if needed.'], 'security');
        }
        $request->session()->forget('two_factor.setup');
        $request->session()->regenerate();

        return redirect()->to(route('profile.edit').'#security')->with('security_status', 'Two-factor authentication has been enabled.');
    }

    public function resend(Request $request, TwoFactorCodes $codes): RedirectResponse
    {
        $binding = $request->session()->get('two_factor.setup.binding', '');
        $challenge = TwoFactorChallenge::where('user_id', $request->user()->id)->where('purpose', 'setup')
            ->where('binding_hash', hash('sha256', $binding))->first();
        if (! $challenge || $request->session()->get('two_factor.setup.expires_at', 0) <= now()->timestamp
            || ! hash_equals($challenge->context_hash, $codes->context($request->user()))) {
            throw ValidationException::withMessages(['two_factor' => 'Please start setup again with your current password.'])->errorBag('security');
        }
        $this->sendSetupCode($codes, $request->user(), $binding);

        return redirect()->to(route('profile.edit').'#security')->with('security_status', 'A new security code has been sent. Previous codes no longer work.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validateWithBag('security', ['current_password' => ['required', 'current_password']]);
        DB::transaction(function () use ($request) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $user->two_factor_method = null;
            $user->save();
            TwoFactorChallenge::where('user_id', $user->id)->delete();
        });
        $request->session()->forget(['two_factor.setup', 'two_factor.login']);

        return redirect()->to(route('profile.edit').'#security')->with('security_status', 'Two-factor authentication has been disabled.');
    }

    public function cancelSetup(Request $request, TwoFactorCodes $codes): RedirectResponse
    {
        $binding = $request->session()->get('two_factor.setup.binding');
        if ($binding) {
            $codes->cancel($request->user()->id, 'setup', $binding);
        }
        $request->session()->forget('two_factor.setup');

        return redirect()->to(route('profile.edit').'#security');
    }

    private function sendSetupCode(TwoFactorCodes $codes, User $user, string $binding): void
    {
        try {
            $codes->issue($user, 'setup', $binding);
        } catch (ValidationException $exception) {
            throw $exception->errorBag('security');
        }
    }
}
