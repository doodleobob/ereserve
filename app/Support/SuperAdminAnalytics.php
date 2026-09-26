<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SuperAdminAnalytics
{
    public static function forRequest(Request $request): array
    {
        abort_unless($request->user()->role === 'super_admin', 403);

        $range = AnalyticsPeriod::forRequest($request, systemWide: true);

        // Barangays are string attributes, not a separate registered-barangays table.
        // Count actual representation, not every name in the registration dropdown.
        $barangays = User::query()->whereIn('role', ['admin', 'user'])->select('barangay')
            ->union(Facility::query()->select('barangay'))
            ->union(Reservation::query()->select('barangay'))
            ->orderBy('barangay')->pluck('barangay')->filter(fn ($name) => filled($name))->values()->all();
        $input = $request->validate([
            'barangay' => ['nullable', 'string', Rule::in($barangays)],
        ]);
        $period = $range->period;
        $barangay = $input['barangay'] ?? null;
        $query = $range->apply(Reservation::query())
            ->when($barangay, fn ($query) => $query->where('barangay', $barangay));
        $start = $range->start;
        $summary = AdminAnalytics::summary(clone $query);
        $byBarangay = (clone $query)->selectRaw('barangay, COUNT(*) AS total')
            ->groupBy('barangay')->orderByDesc('total')->orderBy('barangay')->get()
            ->map(fn ($row) => ['barangay' => $row->barangay, 'count' => (int) $row->total])->all();

        // Only grouped monthly rows are loaded, on both supported database drivers.
        $monthSql = $query->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')";
        $monthly = (clone $query)->selectRaw($monthSql.' AS month, COUNT(*) AS total')
            ->groupByRaw($monthSql)->orderBy('month')->pluck('total', 'month');
        $firstMonth = $period === 'all' && $monthly->isNotEmpty()
            ? Carbon::parse($monthly->keys()->first().'-01') : ($start?->copy() ?? today()->startOfYear());
        $firstMonth->startOfMonth();
        $lastMonth = $range->end?->copy()->startOfMonth()
            ?? ($monthly->isNotEmpty() ? Carbon::parse($monthly->keys()->last().'-01') : today()->startOfMonth());
        $series = [];
        for ($month = $firstMonth->copy(); $month->lte($lastMonth); $month->addMonth()) {
            $series[] = ['month' => $month->format('Y-m'), 'count' => (int) $monthly->get($month->format('Y-m'), 0)];
        }
        $accounts = $range->apply(User::query())->when($barangay, fn ($query) => $query->where('barangay', $barangay))
            ->selectRaw("COALESCE(SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END), 0) AS admins,
                COALESCE(SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END), 0) AS residents")->first();

        // No barangay registration timestamp exists. Count representation by
        // accounts, facilities, or reservations created within the same period.
        $periodBarangays = $range->apply(User::query())->whereIn('role', ['admin', 'user'])->select('barangay')
            ->union($range->apply(Facility::query())->select('barangay'))
            ->union($range->apply(Reservation::query())->select('barangay'))
            ->pluck('barangay')->filter(fn ($name) => filled($name) && (! $barangay || $name === $barangay));

        return [
            'period' => $period,
            'barangay' => $barangay,
            'barangays' => $barangays,
            'start' => $start?->toDateString(),
            'end' => $range->end?->toDateString(),
            'totalBarangays' => $periodBarangays->count(),
            'totalAdmins' => (int) $accounts->admins,
            'totalResidents' => (int) $accounts->residents,
            'totalReservations' => (int) $summary->requests,
            'statuses' => ['Pending' => (int) $summary->pending, 'Accepted' => (int) $summary->booked, 'Rejected' => (int) $summary->rejected],
            'collected' => number_format((float) $summary->collected, 2, '.', ''),
            'unrecorded' => (int) $summary->unrecorded,
            'byBarangay' => $byBarangay,
            'series' => $series,
            'mostActive' => array_slice($byBarangay, 0, 5),
            'mostUsed' => AdminAnalytics::ranking(clone $query, 5),
        ];
    }
}
