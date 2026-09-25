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
        $isAdmin = $this->isAdminOrSuperAdmin($request);
        $facilities = FacilityCatalog::allForUser($request->user());
        $selectedFacility = null;

        if ($facilities->isNotEmpty()) {
            $selectedFacility = FacilityCatalog::findForUser(
                $request->query('facility', $facilities->first()['slug']),
                $request->user()
            ) ?? $facilities->first();
        }
        $dashboardReservations = $isAdmin
            ? $this->scopedReservationQuery($request)->with('user')->latest()->get()
            : collect();

        $month = $this->selectedMonth($request);
        $selectedDate = $this->selectedDate($request, $month);
        $barangay = $this->barangayScope($request);
        $facilityAvailable = $selectedFacility['is_available'] ?? false;
        $schedule = $selectedFacility
            ? ReservationAvailability::daySchedule($selectedFacility['id'], $selectedDate, $barangay, $facilityAvailable)
            : collect();
        $calendarEvents = $facilityAvailable
            ? $this->calendarEvents($request, $selectedFacility['id'], $month)
            : collect();

        return view('dashboard', [
            'isAdmin' => $isAdmin,
            'facilities' => $facilities,
            'facilityCount' => $facilities->count(),
            'pendingCount' => $dashboardReservations->where('status', 'pending')->count(),
            'acceptedCount' => $dashboardReservations->where('status', 'accepted')->count(),
            'rejectedCount' => $dashboardReservations->where('status', 'rejected')->count(),
            'recentReservations' => $dashboardReservations->take(4),
            'selectedFacility' => $selectedFacility,
            'month' => $month,
            'previousMonth' => $month->copy()->subMonth(),
            'nextMonth' => $month->copy()->addMonth(),
            'selectedDate' => $selectedDate,
            'calendarDays' => $selectedFacility
                ? ReservationAvailability::monthCalendar($selectedFacility['id'], $month, $barangay, $facilityAvailable)
                : collect(),
            'schedule' => $schedule,
            'selectedSlot' => $this->selectedSlot($request, $schedule, $calendarEvents),
            'calendarEvents' => $calendarEvents,
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

    private function selectedSlot(Request $request, $schedule, $calendarEvents): ?array
    {
        $startTime = $request->query('start_time');
        $endTime = $request->query('end_time');

        if (! $startTime || ! $endTime) {
            return null;
        }

        $scheduleSlot = $schedule->first(function (array $slot) use ($startTime, $endTime) {
            return $slot['start_time'] === $startTime
                && $slot['end_time'] === $endTime
                && in_array($slot['status'], ['available', 'booked', 'in_use'], true);
        });

        if ($scheduleSlot !== null) {
            return $scheduleSlot;
        }

        $selectedDate = $request->query('date');
        $event = $calendarEvents->first(function (array $event) use ($selectedDate, $startTime, $endTime) {
            return substr($event['start'], 0, 10) === $selectedDate
                && Carbon::parse($event['start'])->format('H:i') === $startTime
                && Carbon::parse($event['end'])->format('H:i') === $endTime;
        });

        if ($event === null) {
            return null;
        }

        return [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'label' => Carbon::parse($event['start'])->format('g:i A').' - '.Carbon::parse($event['end'])->format('g:i A').($event['note'] ? ' · '.$event['note'] : ''),
            'status' => $event['title'] === 'In Use' ? 'in_use' : 'booked',
        ];
    }

    private function adminReservations(Request $request)
    {
        if (! $this->isAdminOrSuperAdmin($request)) {
            return collect();
        }

        $query = $this->scopedReservationQuery($request)
            ->with('user')
            ->orderByDesc('reservation_date')
            ->orderBy('start_time');

        if ($request->filled('facility')) {
            $query->where('facility_slug', $request->query('facility'));
        }

        if ($request->filled('admin_date')) {
            $date = Carbon::parse($request->query('admin_date'))->startOfDay();
            $query->whereBetween('reservation_date', [$date->copy()->subDay()->toDateString(), $date->toDateString()]);
        }

        if (in_array($request->query('admin_status'), ['pending', 'accepted', 'rejected'], true)) {
            $query->where('status', $request->query('admin_status'));
        }

        if (isset($date)) {
            return $query->get()->filter(fn (Reservation $reservation) => $reservation->period()->overlaps($date, $date->copy()->addDay()))->take(50);
        }

        return $query->limit(50)->get();
    }

    private function scopedReservationQuery(Request $request)
    {
        $query = Reservation::query();

        if ($request->user()->role !== 'super_admin') {
            $query->where('barangay', $request->user()->barangay);
        }

        return $query;
    }

    private function barangayScope(Request $request): ?string
    {
        return $request->user()->role === 'super_admin' ? null : $request->user()->barangay;
    }

    private function calendarEvents(Request $request, ?int $facilityId, Carbon $month)
    {
        if ($facilityId === null) {
            return collect();
        }

        return ReservationAvailability::acceptedReservations(
            $facilityId,
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth(),
            $this->barangayScope($request)
        )->flatMap(function (Reservation $reservation) use ($month) {
            $displayStatus = ReservationAvailability::displayStatus($reservation);
            $period = $reservation->period();
            $events = [];
            for ($day = $period->start->copy()->startOfDay(); $day->lt($period->end); $day->addDay()) {
                if (! $day->isSameMonth($month)) {
                    continue;
                }
                $continued = ! $day->isSameDay($period->start);
                $events[] = [
                    'title' => $displayStatus === 'in_use' ? 'In Use' : 'Booked',
                    'start' => ($continued ? $day : $period->start)->format('Y-m-d\TH:i:s'),
                    'end' => $period->end->format('Y-m-d\TH:i:s'),
                    'note' => $continued ? 'Continued from '.$period->start->format('M j, Y') : $period->endDateLabel(),
                    'color' => 'red',
                ];
            }

            return $events;
        })->values();
    }

    private function isAdminOrSuperAdmin(Request $request): bool
    {
        return in_array($request->user()->role, ['admin', 'super_admin'], true);
    }
}
