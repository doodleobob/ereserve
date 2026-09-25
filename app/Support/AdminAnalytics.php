<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminAnalytics
{
    public static function forRequest(Request $request, Builder $scopedReservations): array
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);

        $input = $request->validate([
            'analytics_period' => ['sometimes', Rule::in(['7', '30', 'month', 'custom'])],
            'analytics_start' => ['exclude_unless:analytics_period,custom', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'analytics_end' => ['exclude_unless:analytics_period,custom', 'required', 'date_format:Y-m-d', 'after_or_equal:analytics_start', 'before_or_equal:today'],
        ]);
        $period = $input['analytics_period'] ?? '30';
        $end = today();
        $start = match ($period) {
            '7' => today()->subDays(6),
            'month' => today()->startOfMonth(),
            'custom' => Carbon::parse($input['analytics_start'])->startOfDay(),
            default => today()->subDays(29),
        };
        if ($period === 'custom') {
            $end = Carbon::parse($input['analytics_end'])->startOfDay();
        }
        if ($start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['analytics_end' => 'Choose a range of at most 366 days.']);
        }

        // Reuse the dashboard scope. Never trust a requested barangay or facility.
        $query = (clone $scopedReservations)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end->copy()->addDay());
        $summary = (clone $query)->selectRaw("COUNT(*) AS requests,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END), 0) AS booked,
            COALESCE(SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END), 0) AS rejected,
            COALESCE(SUM(CASE WHEN status = 'accepted' THEN total_payment ELSE 0 END), 0) AS collected,
            COALESCE(SUM(CASE WHEN status = 'accepted' AND total_payment IS NULL THEN 1 ELSE 0 END), 0) AS unrecorded")->first();

        $daily = (clone $query)->selectRaw("DATE(created_at) AS day, COUNT(*) AS requests,
            COALESCE(SUM(CASE WHEN status = 'accepted' THEN total_payment ELSE 0 END), 0) AS collected")
            ->groupByRaw('DATE(created_at)')->orderBy('day')->get()->keyBy('day');
        $series = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $row = $daily->get($day->toDateString());
            $series[] = [
                'date' => $day->toDateString(),
                'requests' => (int) ($row?->requests ?? 0),
                'collected' => number_format((float) ($row?->collected ?? 0), 2, '.', ''),
            ];
        }

        return [
            'period' => $period,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'requests' => (int) $summary->requests,
            'pending' => (int) $summary->pending,
            'booked' => (int) $summary->booked,
            'rejected' => (int) $summary->rejected,
            'collected' => number_format((float) $summary->collected, 2, '.', ''),
            'unrecorded' => (int) $summary->unrecorded,
            'series' => $series,
            'mostRequested' => self::ranking(clone $query),
            'mostBooked' => self::ranking((clone $query)->where('status', 'accepted')),
        ];
    }

    private static function ranking(Builder $query): array
    {
        // IDs survive renames; snapshots keep deleted facilities in historical reports.
        // Barangay and legacy slug prevent unrelated deleted facilities being merged.
        return $query->selectRaw("barangay, facility_id,
            CASE WHEN facility_id IS NULL THEN facility_slug ELSE '' END AS legacy_slug,
            MAX(facility_name) AS name, MAX(category) AS category, COUNT(*) AS total")
            ->groupBy('barangay', 'facility_id')
            ->groupByRaw("CASE WHEN facility_id IS NULL THEN facility_slug ELSE '' END")
            ->orderByDesc('total')->orderBy('name')->orderBy('barangay')->orderBy('facility_id')->orderBy('legacy_slug')
            ->limit(10)->get()->map(fn ($row) => [
                'name' => $row->name, 'barangay' => $row->barangay,
                'category' => $row->category, 'count' => (int) $row->total,
            ])->all();
    }
}
