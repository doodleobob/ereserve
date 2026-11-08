<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesAccounts;
use App\Models\User;
use App\Services\Authorization;
use App\Support\AdminPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ResidentController extends Controller
{
    use ManagesAccounts;

    protected string $accountRoute = 'residents';

    protected function managedAccounts(Request $request): Builder
    {
        Gate::authorize('viewAny', User::class);

        return User::query()->where('role', 'user')
            ->when($request->user()->role === 'admin', fn (Builder $query) => $query->where('barangay', $request->user()->barangay));
    }

    protected function canManageAccounts(Request $request): bool
    {
        return app(Authorization::class)->allows($request->user(), AdminPermission::RESIDENTS_MANAGE);
    }

    protected function authorizeAccountView(Request $request, User $account): void
    {
        Gate::authorize('view', $account);
    }

    protected function accountForStatus(Request $request, string $account): User
    {
        $resident = User::query()->where('role', 'user')
            ->when($request->user()->role === 'admin', fn (Builder $query) => $query->where('barangay', $request->user()->barangay))
            ->findOrFail($account);
        Gate::authorize('manage', $resident);

        return $resident;
    }
}
