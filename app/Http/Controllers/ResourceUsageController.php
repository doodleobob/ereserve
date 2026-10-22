<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\ResourceUsage;
use App\Services\ResourceUsageManagement;
use App\Support\ResourceUsageQuery;
use App\Support\ResourceUsageReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ResourceUsageController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', ResourceUsage::class);
        $records = ResourceUsageQuery::forRequest($request)->paginate((int) ($request->query('per_page') ?: 10))->withQueryString();

        return view('shared.resource-usage.index', ['records' => $records, 'systemWide' => $request->user()->role === 'super_admin']);
    }

    public function store(Request $request, Reservation $reservation, ResourceUsageManagement $service)
    {
        $usage = $service->record($request->user(), $reservation, $request->all());
        $message = 'Resource usage confirmed. Status: '.ResourceUsage::STATUSES[$usage->status()].'.';

        return $request->expectsJson() ? response()->json(['success' => true, 'message' => $message, 'usage_id' => $usage->id])
            : redirect()->route('resource-usage.index')->with('usage_status', $message);
    }

    public function export(Request $request)
    {
        Gate::authorize('viewAny', ResourceUsage::class);
        $data = $request->validate(['format' => ['required', 'in:pdf,xlsx']]);

        return ResourceUsageReport::download($request, $data['format']);
    }
}
