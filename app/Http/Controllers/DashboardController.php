<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Support\DashboardOverview;
use App\Support\FacilityCatalog;
use App\Support\ReservationCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if (in_array($request->user()->role, ['admin', 'super_admin'], true)) {
            return view('dashboards.operations', DashboardOverview::forUser($request->user()));
        }

        // Keep bookmarked Dashboard calendar selections without a second calendar endpoint.
        $context = $request->only(['barangay', 'type', 'facility', 'resource', 'month', 'date', 'start_time', 'end_time', 'end_next_day', 'quantity']);
        if ($context !== []) {
            $slug = $context['facility'] ?? $context['resource'] ?? null;
            $facility = is_string($slug) ? FacilityCatalog::findForUser($slug, $request->user()) : null;
            $request->session()->reflash();
            if ($facility && ($context['barangay'] ?? $facility['barangay']) === $facility['barangay']) {
                unset($context['facility'], $context['resource']);

                return redirect()->route(FacilityCatalog::detailRoute($facility['category']), ['slug' => $facility['slug']] + $context);
            }

            return redirect()->route('calendar', $context);
        }

        $mine = Reservation::query()->where('user_id', $request->user()->id);
        $counts = (clone $mine)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $now = now('Asia/Manila');
        // Yesterday's bookings can still be in progress after midnight.
        $upcoming = (clone $mine)->where('status', 'accepted')
            ->where('reservation_date', '>=', $now->copy()->subDay()->toDateString())
            ->orderBy('reservation_date')->orderBy('start_time')->orderBy('id')->cursor()
            ->first(fn (Reservation $reservation) => $reservation->period()->end->gt($now));

        return view('dashboards.resident', [
            'pendingCount' => $counts['pending'] ?? 0,
            'acceptedCount' => $counts['accepted'] ?? 0,
            'rejectedCount' => $counts['rejected'] ?? 0,
            'upcomingReservation' => $upcoming,
        ]);
    }

    public function calendar(Request $request): View|RedirectResponse
    {
        if ($request->query('barangay') === 'all') {
            return redirect()->route('calendar', $request->except(['barangay', 'facility', 'resource']));
        }

        return view('calendar.index', (new ReservationCalendar)->forRequest($request));
    }
}
