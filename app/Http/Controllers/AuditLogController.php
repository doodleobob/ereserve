<?php

namespace App\Http\Controllers;

use App\Models\AuditLogEntry;
use App\Services\AuditLogger;
use App\Support\Barangays;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-admin-accounts');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'barangay' => ['nullable', Rule::in(Barangays::ALL)],
            'actor_role' => ['nullable', Rule::in(['admin', 'super_admin'])],
            'action' => ['nullable', Rule::in(array_keys(AuditLogger::descriptions()))],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = AuditLogEntry::query()->with('actor')->orderByDesc('occurred_at')->orderByDesc('id');
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(function ($q) use ($search): void {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('target_type', 'like', "%{$search}%")
                    ->orWhere('target_id', 'like', "%{$search}%")
                    ->orWhere('actor_name', 'like', "%{$search}%")
                    ->orWhereHas('actor', fn ($actor) => $actor->where('email', 'like', "%{$search}%"));
            });
        }
        if (! empty($filters['barangay'])) $query->where('barangay', $filters['barangay']);
        if (! empty($filters['actor_role'])) $query->where('actor_role', $filters['actor_role']);
        if (! empty($filters['action'])) $query->where('action', $filters['action']);
        if (! empty($filters['from_date'])) $query->whereDate('occurred_at', '>=', $filters['from_date']);
        if (! empty($filters['to_date'])) $query->whereDate('occurred_at', '<=', $filters['to_date']);

        return view('super_admin.audit-logs.index', [
            'entries' => $query->paginate(25)->withQueryString(),
            'filters' => $filters,
            'barangays' => Barangays::ALL,
            'actions' => AuditLogger::descriptions(),
        ]);
    }

    public function show(AuditLogEntry $auditLog): View
    {
        Gate::authorize('manage-admin-accounts');
        $auditLog->load('actor');
        return view('super_admin.audit-logs.show', compact('auditLog'));
    }
}
