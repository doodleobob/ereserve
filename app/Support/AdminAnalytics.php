<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AdminAnalytics
{
    // Preserve legacy accepted totals without receipts, but never count a known
    // pending, cancelled or refund payment as paid revenue.
    private const COLLECTED = "status = 'accepted' AND NOT EXISTS (SELECT 1 FROM payments WHERE payments.reservation_id = reservations.id AND payments.payment_status <> 'paid')";

    public static function forRequest(Request $request, Builder $scopedReservations): array
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);

        $range = AnalyticsPeriod::forRequest($request);
        $period = $range->period;
        $start = $range->start;
        $end = $range->end;

        // Reuse the dashboard scope. Never trust a requested barangay or facility.
        $query = $range->apply(clone $scopedReservations);
        $summary = self::summary(clone $query);

        $collected = self::COLLECTED;
        $daily = (clone $query)->selectRaw("DATE(created_at) AS day, COUNT(*) AS requests,
            COALESCE(SUM(CASE WHEN {$collected} THEN total_payment ELSE 0 END), 0) AS collected")
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
            'refunds' => self::refunds(clone $query),
            'series' => $series,
            'mostRequested' => self::ranking(clone $query),
            'mostBooked' => self::ranking((clone $query)->where('status', 'accepted')),
        ];
    }

    public static function refunds(Builder $query): array
    {
        // Use the same reservation scope and submission period as the analytics.
        // Keep outstanding obligations separate from money actually returned.
        $rows = Payment::query()->whereIn('reservation_id', $query->reorder()->select('reservations.id'))
            ->join('reservations', 'payments.reservation_id', '=', 'reservations.id')
            ->whereIn('payment_status', ['refund_pending', 'refunded'])
            ->selectRaw('payment_status, COUNT(*) AS records, COALESCE(SUM(ROUND(reservations.total_payment * 100)), 0) AS total_cents')
            ->groupBy('payment_status')->get()->keyBy('payment_status');

        $refunds = [];
        foreach (['refund_pending', 'refunded'] as $status) {
            $row = $rows->get($status);
            $refunds[$status] = ['count' => (int) ($row?->records ?? 0), 'total' => Money::decimal((int) ($row?->total_cents ?? 0))];
        }

        return $refunds;
    }

    public static function summary(Builder $query): object
    {
        $collected = self::COLLECTED;

        return $query->selectRaw("COUNT(*) AS requests,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END), 0) AS booked,
            COALESCE(SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END), 0) AS rejected,
            COALESCE(SUM(CASE WHEN {$collected} THEN total_payment ELSE 0 END), 0) AS collected,
            COALESCE(SUM(CASE WHEN {$collected} AND total_payment IS NULL THEN 1 ELSE 0 END), 0) AS unrecorded")->first();
    }

    public static function ranking(Builder $query, int $limit = 10): array
    {
        // IDs survive renames; snapshots keep deleted facilities in historical reports.
        // Barangay and legacy slug prevent unrelated deleted facilities being merged.
        return $query->selectRaw("barangay, facility_id,
            CASE WHEN facility_id IS NULL THEN facility_slug ELSE '' END AS legacy_slug,
            MAX(facility_name) AS name, MAX(category) AS category, COUNT(*) AS total")
            ->groupBy('barangay', 'facility_id')
            ->groupByRaw("CASE WHEN facility_id IS NULL THEN facility_slug ELSE '' END")
            ->orderByDesc('total')->orderBy('name')->orderBy('barangay')->orderBy('facility_id')->orderBy('legacy_slug')
            ->limit($limit)->get()->map(fn ($row) => [
                'name' => $row->name, 'barangay' => $row->barangay,
                'category' => $row->category, 'count' => (int) $row->total,
            ])->all();
    }
}
