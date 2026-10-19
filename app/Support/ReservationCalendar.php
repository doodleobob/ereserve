<?php

namespace App\Support;

use App\Models\Facility;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Shared calendar presentation data; availability and conflict rules stay in their existing services. */
class ReservationCalendar
{
    public function forRequest(Request $request, ?array $fixedFacility = null): array
    {
        $isAdmin = in_array($request->user()->role, ['admin', 'super_admin'], true);
        $readOnlyCalendar = $fixedFacility === null;
        $barangays = Facility::query()->distinct()->pluck('barangay')
            ->push($request->user()->barangay)->filter()->unique()->sort()->values();
        $request->validate([
            'barangay' => ['sometimes', 'string', Rule::in($barangays->all())],
            'facility' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in(['all', 'facility', 'equipment'])],
            'month' => ['sometimes', 'date_format:Y-m'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
        ]);
        $requestedFacility = $fixedFacility['slug'] ?? $request->query('facility');
        $linkedResource = $requestedFacility && $requestedFacility !== 'all' ? Facility::where('slug', $requestedFacility)->first() : null;
        $defaultBarangay = filled($request->user()->barangay) ? $request->user()->barangay : $barangays->first();
        $selectedBarangay = $fixedFacility['barangay'] ?? $request->query('barangay', $linkedResource?->barangay ?? $defaultBarangay);
        $selectedType = $fixedFacility ? strtolower($fixedFacility['category']) : ($request->query('type', $linkedResource ? strtolower($linkedResource->category) : 'all') ?: 'all');
        $facilities = $fixedFacility ? collect([$fixedFacility]) : ($selectedBarangay
            ? FacilityCatalog::calendarResources($request->user(), $selectedBarangay, $selectedType === 'all' ? null : ucfirst($selectedType))
            : collect());
        $selectedFacility = $fixedFacility ?? ($requestedFacility ? $facilities->firstWhere('slug', $requestedFacility) : null);

        $month = $this->selectedMonth($request);
        $selectedDate = $this->selectedDate($request, $month);
        $barangay = $selectedFacility['barangay'] ?? null;
        $facilityAvailable = $selectedFacility['is_available'] ?? false;
        $schedule = $selectedFacility
            ? ReservationAvailability::daySchedule($selectedFacility['id'], $selectedDate, $barangay, $facilityAvailable)
            : collect();
        // Combine already-filtered public events, never resource availability or conflict decisions.
        $eventsForMonth = fn (Carbon $eventMonth) => ($selectedFacility ? collect([$selectedFacility]) : $facilities)
            ->flatMap(function (array $resource) use ($request, $eventMonth, $isAdmin, $selectedFacility) {
                $events = ReservationAvailability::calendarEvents($resource['id'], $eventMonth,
                    $isAdmin && ($request->user()->role === 'super_admin' || $resource['barangay'] === $request->user()->barangay));

                return $selectedFacility ? $events : $events->map(fn (array $event) => $event + [
                    'resource_id' => $resource['id'], 'resource_name' => $resource['name'],
                    'resource_slug' => $resource['slug'], 'resource_category' => $resource['category'],
                    'resource_barangay' => $resource['barangay'],
                ]);
            })->sortBy('start')->values();
        $calendarEvents = $eventsForMonth($month);
        // Month browsing keeps the chosen day, including its schedules outside the visible month.
        $browseDateEvents = ($readOnlyCalendar && ! $selectedDate->isSameMonth($month)
            ? $eventsForMonth($selectedDate->copy()->startOfMonth()) : $calendarEvents)
            ->filter(fn (array $event) => substr($event['start'], 0, 10) === $selectedDate->toDateString())->values();

        $shared = [
            'barangays' => $barangays,
            'selectedBarangay' => $selectedBarangay,
            'selectedType' => $selectedType,
            'allResources' => $selectedFacility === null,
            'calendarRoute' => $fixedFacility ? 'facilities.show' : 'calendar',
            'embeddedCalendar' => $fixedFacility !== null,
            'readOnlyCalendar' => $readOnlyCalendar,
            'browseDateEvents' => $browseDateEvents,
        ];

        $selectionError = null;
        try {
            $selectedSlot = $selectedFacility && ! $readOnlyCalendar ? $this->selectedSlot($request, $schedule, $calendarEvents, $selectedDate, $selectedFacility, $isAdmin) : null;
        } catch (ValidationException $exception) {
            $selectedSlot = null;
            $selectionError = collect($exception->errors())->flatten()->first();
        }

        $calendarDays = $selectedFacility
            ? ReservationAvailability::monthCalendar($selectedFacility['id'], $month, $barangay, $facilityAvailable)
            : collect(range(1, $month->daysInMonth))->map(fn (int $day) => ['date' => $month->copy()->day($day)]);
        // Reuse the calendar's status for the mobile detail heading, including linked dates outside the visible month.
        $selectedDateDays = ! $readOnlyCalendar && ! $selectedDate->isSameMonth($month)
            ? ReservationAvailability::monthCalendar($selectedFacility['id'], $selectedDate->copy()->startOfMonth(), $barangay, $facilityAvailable)
            : $calendarDays;
        $selectedDateStatus = $selectedDateDays->first(fn (array $day) => $day['date']->isSameDay($selectedDate))['status'] ?? null;

        return $shared + [
            'isAdmin' => $isAdmin,
            'facilities' => $facilities,
            'selectedFacility' => $selectedFacility,
            'month' => $month,
            'previousMonth' => $month->copy()->subMonth(),
            'nextMonth' => $month->copy()->addMonth(),
            'selectedDate' => $selectedDate,
            'calendarDays' => $calendarDays,
            'selectedDateStatus' => $selectedDateStatus,
            'schedule' => $schedule,
            'selectedSlot' => $selectedSlot,
            'selectionError' => $selectionError,
            'calendarEvents' => $calendarEvents,
        ];
    }

    private function selectedMonth(Request $request): Carbon
    {
        if ($request->filled('month')) {
            return Carbon::createFromFormat('!Y-m', $request->query('month'), 'Asia/Manila')->startOfMonth();
        }

        return $request->filled('date') ? Carbon::parse($request->query('date'), 'Asia/Manila')->startOfMonth() : today('Asia/Manila')->startOfMonth();
    }

    private function selectedDate(Request $request, Carbon $month): Carbon
    {
        if ($request->filled('date')) {
            return Carbon::parse($request->query('date'), 'Asia/Manila')->startOfDay();
        }

        return $month->isSameMonth(today('Asia/Manila')) ? today('Asia/Manila') : $month->copy()->startOfMonth();
    }

    private function selectedSlot(Request $request, $schedule, $calendarEvents, Carbon $selectedDate, array $facility, bool $isAdmin): ?array
    {
        $startTime = $request->query('start_time');
        $endTime = $request->query('end_time');

        if (! $startTime || ! $endTime) {
            return null;
        }

        $period = new ReservationPeriod($selectedDate->toDateString(), $startTime, $endTime);
        if (! $isAdmin) {
            if (! $facility['is_available'] || ! $facility['can_reserve']) {
                return null;
            }
            FutureReservationStart::validate($selectedDate->toDateString(), $startTime);
            if (! $period->isValid()) {
                throw ValidationException::withMessages(['end_time' => 'Start and End Time must be different.']);
            }
            $resource = Facility::findOrFail($facility['id']);
            if (! $resource->isEquipment()) {
                OfficialUseScheduling::validateAvailability($resource, $period);
            }

            // Competing requests remain permitted by the existing approval workflow.
            return [
                'start_time' => $startTime, 'end_time' => $endTime,
                'available_quantity' => $resource->isEquipment() ? ReservationAvailability::availableQuantity($resource, $period) : null,
                'label' => $period->start->format('g:i A').' - '.$period->end->format('g:i A').($period->endDateLabel() ? ' · '.$period->endDateLabel() : ''),
            ];
        }

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
}
