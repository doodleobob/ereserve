<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesAccounts;
use App\Models\User;
use App\Support\Barangays;
use App\Support\PhoneNumber;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AdminController extends Controller
{
    use ManagesAccounts;

    protected string $accountRoute = 'admins';

    protected function managedAccounts(Request $request): Builder
    {
        $this->authorizeSuperAdmin($request);

        return User::query()->where('role', 'admin');
    }

    public function create(Request $request): View
    {
        $this->authorizeSuperAdmin($request);

        return view('admins.create', [
            'barangays' => Barangays::ALL,
            'admins' => User::query()
                ->where('role', 'admin')
                ->orderBy('barangay')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => PhoneNumber::rules(),
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'barangay' => ['required', Rule::in(Barangays::ALL)],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], PhoneNumber::messages());

        $user = User::create($validated + [
            'role' => 'admin',
        ]);

        try {
            event(new Registered($user));
        } catch (TransportExceptionInterface|ValidationException $exception) {
            return back()->with('admin_status', 'Admin account created, but the verification email could not be sent. The admin can log in and resend it.');
        }

        return back()->with('admin_status', 'Admin account created successfully.');
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()->role === 'super_admin', 403);
    }
}
