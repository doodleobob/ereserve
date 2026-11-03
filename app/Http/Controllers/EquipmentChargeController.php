<?php

namespace App\Http\Controllers;

use App\Models\EquipmentCharge;
use App\Models\ResourceUsage;
use App\Services\EquipmentChargeManagement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EquipmentChargeController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', EquipmentCharge::class);
        $user = $request->user();
        $query = EquipmentCharge::query()->with(['usage.reservation.user', 'usage.facility', 'creator'])->latest();
        $query->whereHas('usage', fn ($q) => $q->where('barangay', $user->barangay));
        if ($request->filled('search')) {
            $term = '%'.trim($request->query('search')).'%';
            $query->where(fn ($q) => $q->where('reason', 'like', $term)->orWhereHas('usage', fn ($u) => $u->where('id', 'like', $term)->orWhereHas('facility', fn ($f) => $f->where('name', 'like', $term)))->orWhereHas('usage.reservation.user', fn ($r) => $r->where('name', 'like', $term)));
        }
        $charges = $query->paginate(10)->withQueryString();
        $canManage = true;
        $usages = ResourceUsage::with(['reservation.user', 'facility'])->where('resource_type', 'Equipment')->where('barangay', $user->barangay)->whereColumn('released_quantity', '>', 'returned_quantity')->whereHas('reservation', fn ($q) => $q->where('barangay', $user->barangay))->latest()->get();

        return view('shared.equipment-charges.index', compact('charges', 'canManage', 'usages'));
    }

    public function show(Request $request, EquipmentCharge $charge)
    {
        $charge->load(['usage.reservation.user', 'usage.facility', 'creator', 'payments.recorder']);
        Gate::authorize('view', $charge);

        return view('shared.equipment-charges.show', compact('charge'));
    }

    public function store(Request $request, EquipmentChargeManagement $management)
    {
        Gate::authorize('create', EquipmentCharge::class);
        $charge = $management->create($request->user(), $request->all());

        return redirect()->route('equipment-charges.show', $charge)->with('charge_status', 'Equipment charge recorded as unpaid.');
    }

    public function edit(Request $request, EquipmentCharge $charge)
    {
        Gate::authorize('manage', $charge);
        $charge->load(['usage.reservation.user', 'usage.facility']);

        return view('shared.equipment-charges.edit', compact('charge'));
    }

    public function update(Request $request, EquipmentCharge $charge, EquipmentChargeManagement $management)
    {
        $management->update($request->user(), $charge, $request->all());

        return redirect()->route('equipment-charges.show', $charge)->with('charge_status', 'Equipment charge updated.');
    }
}
