<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Reservation;
use App\Support\Barangays;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

trait ManagesAccounts
{
    public function index(Request $request): View
    {
        $query = $this->managedAccounts($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'barangay' => ['nullable', Rule::in(Barangays::ALL)],
        ]);
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%'));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }
        if ($this->accountRoute === 'admins' && ($barangay = $filters['barangay'] ?? null)) {
            $query->where('barangay', $barangay);
        }

        return view('accounts.index', [
            'accounts' => $query->orderBy('name')->orderBy('id')->paginate(15)->withQueryString(),
            'accountRoute' => $this->accountRoute,
            'barangays' => Barangays::ALL,
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, string $account): View
    {
        $user = $this->managedAccounts($request)->findOrFail($account);

        return view('accounts.show', [
            'account' => $user,
            'accountRoute' => $this->accountRoute,
            'reservations' => $this->accountRoute === 'residents'
                ? Reservation::query()->where('user_id', $user->id)->inBarangayFor($request->user())
                    ->orderByDesc('reservation_date')->orderByDesc('id')->paginate(15)
                : null,
        ]);
    }

    public function updateStatus(Request $request, string $account): RedirectResponse
    {
        $user = $this->managedAccounts($request)->findOrFail($account);
        $request->validate(['is_active' => ['required', 'boolean']]);
        $user->is_active = $request->boolean('is_active');
        $user->save();

        return back()->with('account_status', $user->is_active ? 'Account activated.' : 'Account deactivated.');
    }
}
