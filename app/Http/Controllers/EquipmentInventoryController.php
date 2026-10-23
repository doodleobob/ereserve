<?php

namespace App\Http\Controllers;

use App\Models\EquipmentInventoryAdjustment;
use App\Models\Facility;
use App\Services\ResourceUsageManagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EquipmentInventoryController extends Controller
{
    public static function viewData(Request $request): array
    {
        Gate::authorize('viewAny', EquipmentInventoryAdjustment::class);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:200'], 'per_page' => ['nullable', 'in:10,25,50,100'],
            'history' => ['nullable', 'integer', 'min:1'],
        ]);
        $scope = Facility::where('category', 'Equipment')->when($request->user()->role !== 'super_admin', fn ($query) => $query->where('barangay', $request->user()->barangay));
        $historyEquipment = isset($data['history']) ? (clone $scope)->findOrFail($data['history']) : null;
        $inventoryItems = $scope->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.trim($data['search']).'%'))
            ->when($request->user()->role === 'super_admin' && $request->filled('barangay'), fn ($query) => $query->where('barangay', $request->query('barangay')))
            ->withMax('inventoryAdjustments', 'created_at')->orderBy('name')->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 10))->withQueryString();
        $inventorySummaries = $inventoryItems->getCollection()->mapWithKeys(fn ($facility) => [$facility->id => ResourceUsageManagement::inventory($facility)]);
        $inventoryHistory = $historyEquipment?->inventoryAdjustments()->paginate(10, ['*'], 'history_page')->withQueryString()->fragment('inventory-history');

        return compact('inventoryItems', 'inventorySummaries', 'historyEquipment', 'inventoryHistory');
    }

    public function store(Request $request, Facility $facility, ResourceUsageManagement $management): JsonResponse|RedirectResponse
    {
        $management->adjustInventory($request->user(), $facility, $request->all());
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Inventory adjustment recorded successfully.']);
        }

        return redirect()->route('equipment', ['tab' => 'inventory'])->with('facility_status', 'Inventory adjustment recorded successfully.');
    }
}
