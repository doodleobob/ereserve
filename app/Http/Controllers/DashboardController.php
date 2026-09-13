<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Support\FacilityCatalog;
use App\Support\ReservationAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $facilities = FacilityCatalog::all();
        $selectedFacility = FacilityCatalog::find(
            $request->query('facility', $facilities->first()['slug'])
        ) ?? $facilities->first();

        $month = $this->selectedMonth($request);
        $selectedDate = $this->selectedDate($request, $month);
        $schedule = ReservationAvailability::daySchedule($selectedFacility['slug'], $selectedDate);

        return view('dashboard', [
            'facilities' => $facilities,
            'selectedFacility' => $selectedFacility,
            'month' => $month,
            'previousMonth' => $month->copy()->subMonth(),
            'nextMonth' => $month->copy()->addMonth(),
            'selectedDate' => $selectedDate,
            'calendarDays' => ReservationAvailability::monthCalendar($selectedFacility['slug'], $month),
            'schedule' => $schedule,
            'selectedSlot' => $this->selectedSlot($request, $schedule),
            'adminReservations' => $this->adminReservations($request),
            'selectedAdminStatus' => $request->query('admin_status', 'all'),
            'selectedAdminDate' => $request->query('admin_date', ''),
        ]);
    }

    private function selectedMonth(Request $request): Carbon
    {
        if ($request->filled('month')) {
            return Carbon::createFromFormat('Y-m', $request->query('month'))->startOfMonth();
        }

        return today()->startOfMonth();
    }

    private function selectedDate(Request $request, Carbon $month): Carbon
    {
        if ($request->filled('date')) {
            return Carbon::parse($request->query('date'))->startOfDay();
        }

        return $month->isSameMonth(today()) ? today() : $month->copy()->startOfMonth();
    }

    private function selectedSlot(Request $request, $schedule): ?array
    {
        $startTime = $request->query('start_time');
        $endTime = $request->query('end_time');

        if (! $startTime || ! $endTime) {
            return null;
        }

        return $schedule->first(function (array $slot) use ($startTime, $endTime) {
            return $slot['start_time'] === $startTime
                && $slot['end_time'] === $endTime
                && $slot['status'] === 'available';
        });
    }

    private function adminReservations(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return collect();
        }

        $query = Reservation::query()
            ->with('user')
            ->orderByDesc('reservation_date')
            ->orderBy('start_time');

        if ($request->filled('facility')) {
            $query->where('facility_slug', $request->query('facility'));
        }

        if ($request->filled('admin_date')) {
            $query->whereDate('reservation_date', $request->query('admin_date'));
        }

        if (in_array($request->query('admin_status'), ['pending', 'approved', 'declined', 'cancelled', 'rejected'], true)) {
            $query->where('status', $request->query('admin_status'));
        }

        return $query->limit(50)->get();
    }
}
