<?php

namespace App\Support;

use App\Models\Reservation;
use App\Models\ResourceUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ResourceUsageQuery
{
    public static function forRequest(Request $request): Builder
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:200'], 'barangay' => ['nullable', Rule::in(Barangays::ALL)],
            'type' => ['nullable', 'in:Facility,Equipment'], 'status' => ['nullable', Rule::in(array_keys(ResourceUsage::STATUSES))],
            'from_date' => ['nullable', 'date_format:Y-m-d'], 'to_date' => ['nullable', 'date_format:Y-m-d'],
            'overdue' => ['nullable', 'in:1'], 'outstanding' => ['nullable', 'in:1'],
            'resource_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(array_keys(ResourceUsageReport::columns(true)))],
            'direction' => ['nullable', 'in:asc,desc'], 'per_page' => ['nullable', 'in:10,25,50,100'],
        ]);
        if ($request->filled('from_date') && $request->filled('to_date')) {
            $request->validate(['to_date' => 'after_or_equal:from_date']);
        }
        $type = 'COALESCE(u.resource_type, f.category, reservations.category)';
        $status = "CASE WHEN {$type} = 'Equipment' THEN CASE WHEN COALESCE(u.released_quantity,0) = 0 THEN 'not_released' WHEN u.returned_quantity = u.approved_quantity THEN 'returned' WHEN u.returned_quantity > 0 THEN 'partially_returned' ELSE 'borrowed' END ELSE CASE WHEN u.actual_ended_at IS NOT NULL THEN 'completed' WHEN u.actual_started_at IS NOT NULL THEN 'in_use' ELSE 'not_started' END END";
        $start = DB::getDriverName() === 'sqlite' ? "datetime(reservations.reservation_date || ' ' || reservations.start_time)" : 'TIMESTAMP(reservations.reservation_date, reservations.start_time)';
        $end = DB::getDriverName() === 'sqlite'
            ? "datetime(reservations.reservation_date || ' ' || reservations.end_time, CASE WHEN reservations.end_time < reservations.start_time THEN '+1 day' ELSE '+0 day' END)"
            : 'TIMESTAMP(reservations.reservation_date, reservations.end_time) + INTERVAL (reservations.end_time < reservations.start_time) DAY';
        $outstanding = 'COALESCE(u.released_quantity - u.returned_quantity, 0)';
        $overdue = "({$end}) < ? AND (({$type} = 'Equipment' AND {$outstanding} > 0) OR ({$type} = 'Facility' AND u.actual_started_at IS NOT NULL AND u.actual_ended_at IS NULL))";
        $barangay = 'COALESCE(u.barangay, f.barangay, reservations.barangay)';
        $query = Reservation::query()->leftJoin('resource_usages as u', 'u.reservation_id', '=', 'reservations.id')
            ->leftJoin('facilities as f', 'f.id', '=', 'reservations.facility_id')->leftJoin('users as resident', 'resident.id', '=', 'reservations.user_id')
            ->where(fn ($q) => $q->where('reservations.status', 'accepted')->orWhereNotNull('u.id'))
            ->select('reservations.*')->selectRaw("{$status} as usage_status, {$type} as usage_type, {$barangay} as usage_barangay")
            ->with(['user', 'facility', 'resourceUsage.events', 'resourceUsage.facility']);
        if ($request->user()->role !== 'super_admin') {
            $query->whereRaw("{$barangay} = ?", [$request->user()->barangay]);
        } elseif ($request->filled('barangay')) {
            $query->whereRaw("{$barangay} = ?", [$request->query('barangay')]);
        }
        if ($request->filled('search')) {
            $term = '%'.trim($request->query('search')).'%';
            $query->where(fn ($q) => $q->where('reservations.id', 'like', $term)->orWhere('u.id', 'like', $term)->orWhere('resident.name', 'like', $term)->orWhere('reservations.facility_name', 'like', $term));
        }
        $query->when($request->filled('type'), fn ($q) => $q->whereRaw("{$type} = ?", [$request->query('type')]))
            ->when($request->filled('resource_id'), fn ($q) => $q->whereRaw('COALESCE(u.facility_id, f.id) = ?', [$request->integer('resource_id')]))
            ->when($request->filled('status'), fn ($q) => $q->whereRaw("{$status} = ?", [$request->query('status')]))
            ->when($request->filled('from_date'), fn ($q) => $q->where('reservations.reservation_date', '>=', $request->query('from_date')))
            ->when($request->filled('to_date'), fn ($q) => $q->where('reservations.reservation_date', '<=', $request->query('to_date')))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereRaw($overdue, [now()->format('Y-m-d H:i:s')]))
            ->when($request->boolean('outstanding'), fn ($q) => $q->whereRaw("{$outstanding} > 0"));
        $sorts = ['usage' => 'u.id', 'reservation' => 'reservations.id', 'resident' => 'resident.name', 'barangay' => $barangay,
            'resource' => 'reservations.facility_name', 'type' => $type, 'approved' => 'COALESCE(u.approved_quantity,reservations.quantity)',
            'released' => 'COALESCE(u.released_quantity,0)', 'returned' => 'COALESCE(u.returned_quantity,0)', 'outstanding' => $outstanding,
            'scheduled_start' => $start, 'scheduled_end' => "({$end})", 'actual_start' => 'u.actual_started_at', 'actual_end' => 'u.actual_ended_at',
            'status' => $status, 'overdue' => "CASE WHEN {$overdue} THEN 1 ELSE 0 END"];
        $sort = $request->query('sort') ?: 'reservation';

        return $query->orderByRaw($sorts[$sort].' '.($request->query('direction') ?: 'desc'), $sort === 'overdue' ? [now()->format('Y-m-d H:i:s')] : [])->orderByDesc('reservations.id');
    }
}
