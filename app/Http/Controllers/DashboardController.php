<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Support\DashboardOverview;
use App\Support\FacilityCatalog;
use App\Support\ReservationAvailability;
use App\Support\ReservationPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $isAdmin = $this->isAdminOrSuperAdmin($request);
        if ($isAdmin) {
            return view('dashboards.operations', DashboardOverview::forUser($request->user()));
        }

        return $this->calendar($request);
    }

    public function calendar(Request $request): View|RedirectResponse
    {
        $isAdmin = $this->isAdminOrSuperAdmin($request);
        if ($request->query('facility') === 'all' || $request->query('resource') === 'all' || $request->query('barangay') === 'all') {
            return redirect()->route($request->routeIs('calendar') ? 'calendar' : 'dashboard', $request->except($request->query('barangay') === 'all' ? ['barangay', 'facility', 'resource'] : ['facility', 'resource']));
        }
        $barangays = Facility::query()->distinct()->pluck('barangay')
            ->push($request->user()->barangay)->filter()->unique()->sort()->values();
        $request->validate([
            'barangay' => ['sometimes', 'string', Rule::in($barangays->all())],
            'facility' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in(['facility', 'equipment'])],
            'month' => ['sometimes', 'date_format:Y-m'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
        ]);
        $requestedFacility = $request->query('facility');
        $linkedResource = $requestedFacility ? Facility::where('slug', $requestedFacility)->first() : null;
        $defaultBarangay = filled($request->user()->barangay) ? $request->user()->barangay : $barangays->first();
        $selectedBarangay = $request->query('barangay', $linkedResource?->barangay ?? $defaultBarangay);
        $selectedType = $request->query('type', $linkedResource ? strtolower($linkedResource->category) : null);
        $facilities = $selectedBarangay && $selectedType
            ? FacilityCatalog::calendarResources($request->user(), $selectedBarangay, ucfirst($selectedType))
            : collect();
        $selectedFacility = $requestedFacility ? $facilities->firstWhere('slug', $requestedFacility) : null;

        $month = $this->selectedMonth($request);
        $selectedDate = $this->selectedDate($request, $month);
        $barangay = $selectedFacility['barangay'] ?? null;
        $facilityAvailable = $selectedFacility['is_available'] ?? false;
        $schedule = $selectedFacility
            ? ReservationAvailability::daySchedule($selectedFacility['id'], $selectedDate, $barangay, $facilityAvailable)
            : collect();
        $calendarEvents = $selectedFacility
            ? ReservationAvailability::calendarEvents($selectedFacility['id'], $month, $isAdmin && ($request->user()->role === 'super_admin' || $barangay === $request->user()->barangay))
            : collect();
        if (! $facilityAvailable) {
            $calendarEvents = $calendarEvents->where('event_type', 'official_use')->values();
        }

        $shared = [
            'barangays' => $barangays,
            'selectedBarangay' => $selectedBarangay,
            'selectedType' => $selectedType,
            'calendarRoute' => $request->routeIs('calendar') ? 'calendar' : 'dashboard',
        ];

        return view('dashboard', $shared + [
            'isAdmin' => $isAdmin,
            'facilities' => $facilities,
            'selectedFacility' => $selectedFacility,
            'month' => $month,
            'previousMonth' => $month->copy()->subMonth(),
            'nextMonth' => $month->copy()->addMonth(),
            'selectedDate' => $selectedDate,
            'calendarDays' => $selectedFacility
                ? ReservationAvailability::monthCalendar($selectedFacility['id'], $month, $barangay, $facilityAvailable)
                : collect(),
            'schedule' => $schedule,
            'selectedSlot' => $this->selectedSlot($request, $schedule, $calendarEvents, $selectedDate),
            'calendarEvents' => $calendarEvents,
        ]);
    }

    private function selectedMonth(Request $request): Carbon
    {
        if ($request->filled('month')) {
            return Carbon::createFromFormat('!Y-m', $request->query('month'))->startOfMonth();
        }

        return $request->filled('date') ? Carbon::parse($request->query('date'))->startOfMonth() : today()->startOfMonth();
    }

    private function selectedDate(Request $request, Carbon $month): Carbon
    {
        if ($request->filled('date')) {
            return Carbon::parse($request->query('date'))->startOfDay();
        }

        return $month->isSameMonth(today()) ? today() : $month->copy()->startOfMonth();
    }

    private function selectedSlot(Request $request, $schedule, $calendarEvents, Carbon $selectedDate): ?array
    {
        $startTime = $request->query('start_time');
        $endTime = $request->query('end_time');

        if (! $startTime || ! $endTime) {
            return null;
        }

        $period = new ReservationPeriod($selectedDate->toDateString(), $startTime, $endTime);
        if ($calendarEvents->contains(fn (array $event) => ($event['event_type'] ?? null) === 'official_use'
            && Carbon::parse($event['start'])->lt($period->end) && Carbon::parse($event['end'])->gt($period->start))) {
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

    private function isAdminOrSuperAdmin(Request $request): bool
    {
        return in_array($request->user()->role, ['admin', 'super_admin'], true);
    }
}
