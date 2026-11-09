<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Reservation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use App\Support\Barangays;
use Illuminate\Http\JsonResponse;
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
            'sort' => ['nullable', Rule::in(['name', 'username', 'email', 'barangay', 'status', 'created'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                ->orWhere('username', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%'));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }
        if ($request->user()->role === 'super_admin' && ($barangay = $filters['barangay'] ?? null)) {
            $query->where('barangay', $barangay);
        }

        $sort = ['name' => 'name', 'username' => 'username', 'email' => 'email', 'barangay' => 'barangay', 'status' => 'is_active', 'created' => 'created_at'][$filters['sort'] ?? 'name'];
        $direction = $filters['direction'] ?? 'asc';

        return view('shared.accounts.index', [
            'accounts' => $query->orderBy($sort, $direction)->orderBy('id', $direction)->paginate(15)->withQueryString(),
            'accountRoute' => $this->accountRoute,
            'barangays' => Barangays::ALL,
            'filters' => $filters,
            'canManageAccounts' => $this->canManageAccounts($request),
        ]);
    }

    public function show(Request $request, string $account): View|RedirectResponse
    {
        if ($this->accountRoute === 'admins' && ! $request->ajax()) {
            return redirect()->route('admins.index');
        }

        $user = $this->managedAccounts($request)->findOrFail($account);
        $this->authorizeAccountView($request, $user);

        return view('shared.accounts.show', [
            'account' => $user,
            'accountRoute' => $this->accountRoute,
            'canManageAccounts' => $this->canManageAccounts($request),
            'reservations' => $this->accountRoute === 'residents'
                ? Reservation::query()->where('user_id', $user->id)->inBarangayFor($request->user())->with('facility')
                    ->orderByDesc('reservation_date')->orderByDesc('id')->paginate(15)
                : null,
        ]);
    }

    public function updateStatus(Request $request, string $account): JsonResponse|RedirectResponse
    {
        abort_unless($this->canManageAccounts($request), 403);
        $user = $this->accountForStatus($request, $account);
        // Only the status field is consumed; forged identity and permission fields
        // are deliberately ignored rather than mass-assigned.
        $request->validate(['is_active' => ['required', 'boolean']]);
        $user = DB::transaction(function () use ($request, $account): User {
            $user = $this->accountForStatus($request, $account);
            $before = ['is_active' => $user->is_active];
            $user->is_active = $request->boolean('is_active');
            $user->save();
            if ($before['is_active'] !== $user->is_active) {
                app(AuditLogger::class)->record('resident.status_changed', $user, $before, ['is_active' => $user->is_active]);
            }
            return $user;
        });

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $user->is_active ? 'Account activated.' : 'Account deactivated.']);
        }

        return back()->with('account_status', $user->is_active ? 'Account activated.' : 'Account deactivated.');
    }

    protected function canManageAccounts(Request $request): bool
    {
        return ($this->accountRoute === 'admins' && $request->user()->role === 'super_admin')
            || ($this->accountRoute === 'residents' && $request->user()->role === 'admin');
    }

    protected function authorizeAccountView(Request $request, User $account): void {}

    protected function accountForStatus(Request $request, string $account): User
    {
        return $this->managedAccounts($request)->findOrFail($account);
    }
}
