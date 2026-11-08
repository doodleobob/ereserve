<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesAccounts;
use App\Models\User;
use App\Mail\Transports\GmailApiFailureLogger;
use App\Mail\Transports\GmailApiTransportException;
use App\Services\Authorization;
use App\Services\AuditLogger;
use App\Support\Barangays;
use App\Support\AdminPermission;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class AdminController extends Controller
{
    use ManagesAccounts;

    protected string $accountRoute = 'admins';

    protected function managedAccounts(Request $request): Builder
    {
        Gate::authorize('manage-admin-accounts');

        return User::query()->where('role', 'admin');
    }

    protected function canManageAccounts(Request $request): bool
    {
        return app(Authorization::class)->canManageAdminAccounts($request->user());
    }

    protected function authorizeAccountView(Request $request, User $account): void
    {
        Gate::authorize('manage-admin-account', $account);
    }

    protected function accountForStatus(Request $request, string $account): User
    {
        $admin = $this->managedAccounts($request)->findOrFail($account);
        Gate::authorize('manage-admin-account', $admin);

        return $admin;
    }

    public function create(Request $request): View
    {
        Gate::authorize('manage-admin-accounts');

        return view('super_admin.admins.create', [
            'barangays' => Barangays::ALL,
            'admins' => User::query()
                ->where('role', 'admin')
                ->orderBy('barangay')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-admin-accounts');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => PhoneNumber::rules(),
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'barangay' => ['required', Rule::in(Barangays::ALL)],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], PhoneNumber::messages());

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create($validated + ['role' => 'admin']);
            app(AuditLogger::class)->record('admin.created', $user, null, ['name' => $user->name, 'email' => $user->email, 'barangay' => $user->barangay, 'is_active' => $user->is_active]);
            return $user;
        });

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Admin account created successfully.'], 201);
        }

        return back()->with('admin_status', 'Admin account created successfully.');
    }

    public function edit(Request $request, string $account): View
    {
        $admin = $this->managedAccounts($request)->findOrFail($account);
        Gate::authorize('manage-admin-account', $admin);

        return view('super_admin.admins.edit', [
            'admin' => $admin,
            'barangays' => Barangays::ALL,
        ]);
    }

    public function permissions(Request $request, string $account): View
    {
        Gate::authorize('manage-admin-accounts');
        $admin = $this->managedAccounts($request)->findOrFail($account);
        Gate::authorize('manage-admin-account', $admin);

        $capabilities = AdminPermission::individuallyRestrictable();
        $restricted = $admin->restrictedPermissions()
            ->whereIn('permission', array_map(fn (AdminPermission $permission) => $permission->value, $capabilities))
            ->pluck('permission')->all();

        return view('super_admin.admins.permissions', compact('admin', 'capabilities', 'restricted'));
    }

    public function updatePermissions(Request $request, string $account): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-admin-accounts');
        $admin = $this->managedAccounts($request)->findOrFail($account);
        Gate::authorize('manage-admin-account', $admin);

        $capabilities = AdminPermission::individuallyRestrictable();
        $allowed = array_map(fn (AdminPermission $permission) => $permission->value, $capabilities);
        $validated = $request->validate([
            'restricted_permissions' => ['sometimes', 'array', 'max:'.count($allowed)],
            'restricted_permissions.*' => ['required', 'string', Rule::in($allowed)],
        ]);
        $requestedRestrictions = array_values(array_unique($validated['restricted_permissions'] ?? []));

        DB::transaction(function () use ($request, $admin, $allowed, $requestedRestrictions): void {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('manage-admin-accounts');

            $lockedAdmin = User::query()->where('role', 'admin')->lockForUpdate()->findOrFail($admin->id);
            Gate::forUser($actor)->authorize('manage-admin-account', $lockedAdmin);

            $relation = $lockedAdmin->restrictedPermissions();
            $current = $relation->whereIn('permission', $allowed)->pluck('permission')->all();
            $before = ['restricted_permissions' => $current];
            $remove = array_values(array_diff($current, $requestedRestrictions));
            $add = array_values(array_diff($requestedRestrictions, $current));

            if ($remove !== []) {
                $relation->whereIn('permission', $remove)->delete();
            }

            foreach ($add as $permission) {
                $relation->create(['permission' => $permission]);
            }
            if ($remove !== [] || $add !== []) app(AuditLogger::class)->record('admin.permissions_changed', $lockedAdmin, $before, ['restricted_permissions' => $requestedRestrictions]);
        });

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Admin permissions updated successfully.']);
        }

        return redirect()->route('admins.permissions', $admin)->with('permission_status', 'Admin permissions updated successfully.');
    }

    public function update(Request $request, string $account): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-admin-accounts');

        $admin = $this->managedAccounts($request)->findOrFail($account);
        Gate::authorize('manage-admin-account', $admin);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($admin->id)],
            'phone_number' => PhoneNumber::rules(false),
            'barangay' => ['required', Rule::in(Barangays::ALL)],
        ], PhoneNumber::messages());

        $emailChanged = false;
        try {
            DB::transaction(function () use ($request, $admin, $validated, &$emailChanged): void {
                $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                Gate::forUser($actor)->authorize('manage-admin-accounts');

                $lockedAdmin = User::query()->where('role', 'admin')->lockForUpdate()->findOrFail($admin->id);
                Gate::forUser($actor)->authorize('manage-admin-account', $lockedAdmin);

                if ($lockedAdmin->email !== $validated['email'] && $lockedAdmin->two_factor_method === 'email') {
                    throw ValidationException::withMessages([
                        'email' => 'Disable email two-factor authentication before changing this email address.',
                    ]);
                }

                $emailChanged = $lockedAdmin->email !== $validated['email'];
                $before = ['name' => $lockedAdmin->name, 'email' => $lockedAdmin->email, 'phone_number' => $lockedAdmin->phone_number, 'barangay' => $lockedAdmin->barangay];

                $lockedAdmin->fill([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone_number' => $validated['phone_number'] ?? null,
                    'barangay' => $validated['barangay'],
                ]);
                if ($emailChanged) {
                    $lockedAdmin->email_verified_at = null;
                }
                $lockedAdmin->save();
                $after = ['name' => $lockedAdmin->name, 'email' => $lockedAdmin->email, 'phone_number' => $lockedAdmin->phone_number, 'barangay' => $lockedAdmin->barangay];
                if ($before !== $after) app(AuditLogger::class)->record('admin.updated', $lockedAdmin, $before, $after);
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw ValidationException::withMessages(['email' => 'The email address is already in use.']);
            }

            throw $exception;
        }

        if ($emailChanged) {
            try {
                $admin->fresh()->sendEmailVerificationNotification();
            } catch (TransportExceptionInterface|ValidationException) {
                return redirect()->route('verification.notice')
                    ->withErrors(['verification' => 'The email was updated, but its verification message could not be sent. Please try resending it.']);
            }

            return redirect()->route('verification.notice');
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Admin account updated successfully.']);
        }

        return redirect()->route('admins.show', $admin)->with('account_status', 'Admin account updated successfully.');
    }

    public function sendPasswordReset(Request $request, string $account): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage-admin-accounts');
        $admin = $this->managedAccounts($request)->findOrFail($account);
        Gate::authorize('manage-admin-account', $admin);

        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if (! in_array($transport, ['smtp', 'gmail_api'], true)
            && ! (app()->environment('testing') && $transport === 'array')) {
            return back()->withErrors(['password_reset' => 'Password reset emails are temporarily unavailable. Please try again later.']);
        }

        $correlationId = (string) Str::uuid();
        $resetStatus = PasswordBroker::sendResetLink(['email' => $admin->email], function (User $user, #[\SensitiveParameter] string $token) use ($correlationId): void {
            try {
                $user->sendPasswordResetNotification($token);
            } catch (Throwable $exception) {
                PasswordBroker::deleteToken($user);
                if ($exception instanceof GmailApiTransportException) {
                    GmailApiFailureLogger::log($exception, 'password_reset', $correlationId);
                } else {
                    Log::warning('Password reset email delivery failed.');
                }

                return;
            }

            event(new PasswordResetLinkSent($user));
        });
        if ($resetStatus === PasswordBroker::RESET_LINK_SENT) {
            app(AuditLogger::class)->record('admin.password_reset_requested', $admin, null, ['is_active' => $admin->is_active]);
        }

        $message = 'If password recovery is available for this account, a reset link has been sent.';
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return back()->with('account_status', $message);
    }

    public function updateStatus(Request $request, string $account): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $admin = DB::transaction(function () use ($request, $account, $validated): User {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('manage-admin-accounts');

            $admin = User::query()->where('role', 'admin')->lockForUpdate()->findOrFail($account);
            Gate::forUser($actor)->authorize('manage-admin-account', $admin);
            $before = ['is_active' => $admin->is_active];
            $admin->is_active = (bool) $validated['is_active'];
            $admin->save();
            if ($before['is_active'] !== $admin->is_active) app(AuditLogger::class)->record('admin.status_changed', $admin, $before, ['is_active' => $admin->is_active]);

            return $admin;
        });

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $admin->is_active ? 'Account activated.' : 'Account deactivated.']);
        }

        return back()->with('account_status', $admin->is_active ? 'Account activated.' : 'Account deactivated.');
    }
}
