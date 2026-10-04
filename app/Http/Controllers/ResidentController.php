<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesAccounts;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ResidentController extends Controller
{
    use ManagesAccounts;

    protected string $accountRoute = 'residents';

    protected function managedAccounts(Request $request): Builder
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);

        return User::query()->where('role', 'user')
            ->when($request->user()->role === 'admin', fn (Builder $query) => $query->where('barangay', $request->user()->barangay));
    }
}
