<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Barangays;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AdminController extends Controller
{
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
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'barangay' => ['required', Rule::in(Barangays::ALL)],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        User::create($validated + [
            'role' => 'admin',
        ]);

        return back()->with('admin_status', 'Admin account created successfully.');
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()->role === 'super_admin', 403);
    }
}
