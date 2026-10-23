<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ReservationAvailability
{
    public const BLOCKING_STATUSES = ['accepted'];

    /** Caller holds the resource row lock when changing accepted occupancy. */
    public static function validateBooking(Facility $facility, ReservationPeriod $period, ?int $exceptReservationId = null, ?int $quantity = null): void
    {
        if ($facility->isEquipment() && $facility->total_quantity !== null) {
            self::validateQuantity($facility, $period, $quantity ?? $facility->total_quantity, $exceptReservationId);

            return;
        }
        OfficialUseScheduling::validateAvailability($facility, $period);
        if (self::acceptedReservations($facility->id, $period->start, $period->end, $facility->barangay, $exceptReservationId)
            ->contains(fn (Reservation $reservation) => $reservation->period()->overlaps($period->start, $period->end))) {
            throw ValidationException::withMessages(['reservation' => 'This schedule overlaps an accepted reservation for this resource.']);
        }
    }

    public static function validateAttendees(Facility $facility, int $attendees): void
    {
        if ($facility->isEquipment()) {
            return;
        }
        if ($attendees < 1 || $attendees > $facility->capacity) {
            throw ValidationException::withMessages(['attendees' => "The number of attendees exceeds this facility's maximum capacity of {$facility->capacity} people."]);
        }
    }

    public static function validateQuantity(Facility $facility, ReservationPeriod $period, int $quantity, ?int $exceptReservationId = null): void
    {
        if ($facility->total_quantity === null) {
            throw ValidationException::withMessages(['quantity' => 'Equipment stock has not been verified. Please contact the barangay administrator.']);
        }
        $available = self::availableQuantity($facility, $period, $exceptReservationId);
        if ($quantity < 1 || $quantity > $available) {
            throw ValidationException::withMessages(['quantity' => "Only {$available} units are available for the selected date and time."]);
        }
    }

    public static function availableQuantity(Facility $facility, ReservationPeriod $period, ?int $exceptReservationId = null): int
    {
        return self::allocationSummary($facility, $period, $exceptReservationId)['available'];
    }

    /** Shared by booking validation and the acceptance review; never sum nonconcurrent bookings. */
    public static function allocationSummary(Facility $facility, ReservationPeriod $period, ?int $exceptReservationId = null): array
    {
        $end = $period->allocationEnd($facility->isEquipment());
        $allocated = self::peakAllocation($facility,
            self::acceptedReservations($facility->id, $period->start, $end, $facility->barangay, $exceptReservationId)
                ->concat(OfficialUseScheduling::schedules($facility->id, $period->start, $end, $facility->barangay)),
            $period->start, $end);

        return ['allocated' => $allocated, 'available' => max(0, ($facility->usableQuantity() ?? 0) - $allocated)];
    }

    /** Sweep half-open intervals; grouped end/start deltas make adjacent bookings independent. */
    public static function peakAllocation(Facility $facility, Collection $schedules, Carbon $start, Carbon $end): int
    {
        $deltas = [];
        foreach ($schedules as $schedule) {
            $period = $schedule->period();
            if (! $period->allocationOverlaps($start, $end, $facility->isEquipment())) {
                continue;
            }
            $from = max($start->getTimestamp(), $period->start->getTimestamp());
            $to = min($end->getTimestamp(), $period->allocationEnd($facility->isEquipment())->getTimestamp());
            // A missing quantity preserves the old exclusive-resource contract without backfilling data.
            $quantity = $schedule->quantity ?? $facility->total_quantity ?? 1;
            $deltas[$from] = ($deltas[$from] ?? 0) + $quantity;
            $deltas[$to] = ($deltas[$to] ?? 0) - $quantity;
        }
        ksort($deltas, SORT_NUMERIC);
        $current = $peak = 0;
        foreach ($deltas as $delta) {
            $current += $delta;
            $peak = max($peak, $current);
        }

        return $peak;
    }

    public static function dailySlots(): array
    {
        // Resources have no configured operating-hour restrictions. Cover every permitted hour.
        return array_map(fn (int $hour) => [sprintf('%02d:00', $hour), sprintf('%02d:00', ($hour + 1) % 24)], range(0, 23));
    }

    /** Offer whole-hour boundaries using the same interval and peak-allocation rules as acceptance. */
    public static function hourlyOptions(Facility $facility, array $data): array
    {
        $date = Carbon::parse($data['reservation_date'], 'Asia/Manila')->startOfDay();
        $schedules = self::blockingSchedules($facility->id, $date, $date->copy()->addDays(2), $facility->barangay);
        $available = function (ReservationPeriod $period) use ($facility, $data, $schedules): bool {
            if ($facility->status !== 'Available' || $period->start->lte(now('Asia/Manila'))) {
                return false;
            }

            return $facility->isEquipment()
                ? $facility->total_quantity !== null && self::peakAllocation($facility, $schedules, $period->start, $period->allocationEnd(true)) + (int) ($data['quantity'] ?? 1) <= $facility->usableQuantity()
                : ! $schedules->contains(fn ($schedule) => $schedule->period()->overlaps($period->start, $period->end));
        };
        $starts = $ends = [];
        foreach (self::dailySlots() as [$start, $end]) {
            if ($available(new ReservationPeriod($data['reservation_date'], $start, $end))) {
                $starts[] = $start;
            }
            $selectedStart = $data['start_time'] ?? null;
            if (! $selectedStart || substr($selectedStart, 3) !== '00') {
                continue;
            }
            $overnight = (bool) ($data['end_next_day'] ?? false);
            if (($overnight ? $start < $selectedStart : $start > $selectedStart)
                && $available(new ReservationPeriod($data['reservation_date'], $selectedStart, $start))) {
                $ends[] = $start;
            }
        }

        return ['start_options' => $starts, 'end_options' => $ends];
    }

    public static function monthCalendar(int $facilityId, Carbon $month, ?string $barangay = null, bool $facilityAvailable = true): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $facility = Facility::findOrFail($facilityId);
        $reservations = $facilityAvailable
            ? self::blockingSchedules($facilityId, $start, $end->copy()->addDay(), $barangay)
            : collect();
        $today = today('Asia/Manila');

        return collect(range(1, $end->day))->map(function (int $day) use ($start, $reservations, $today, $facilityAvailable, $facility) {
            $date = $start->copy()->day($day);
            $dateReservations = $reservations->filter(fn ($reservation) => $reservation->period()->allocationOverlaps($date, $date->copy()->addDay()->addMinutes($facility->isEquipment() ? ReservationPeriod::EQUIPMENT_BUFFER_MINUTES : 0), $facility->isEquipment()));
            $slotStatuses = self::slotStatusesForReservations($dateReservations, $date, $facility);
            $availableSlots = $slotStatuses->where('status', 'available')->filter(
                fn (array $slot) => Carbon::parse($date->toDateString().' '.$slot['start_time'], 'Asia/Manila')->gt(now('Asia/Manila'))
            )->count();

            $status = match (true) {
                ! $facilityAvailable || ($facility->isEquipment() && $facility->total_quantity === null) => 'unavailable',
                $date->lt($today) => 'unavailable',
                $availableSlots === 0 => 'full',
                $dateReservations->isNotEmpty() => 'partial',
                default => 'available',
            };

            return [
                'date' => $date,
                'status' => $status,
                'available_slots' => $availableSlots,
            ];
        });
    }

    public static function daySchedule(int $facilityId, Carbon $date, ?string $barangay = null, bool $facilityAvailable = true): Collection
    {
        $reservations = $facilityAvailable
            ? self::blockingSchedules($facilityId, $date, $date->copy()->addDay(), $barangay)
            : collect();

        return self::slotStatusesForReservations($reservations, $date, Facility::findOrFail($facilityId))->map(function (array $slot) use ($date, $facilityAvailable) {
            if (! $facilityAvailable) {
                $slot['status'] = 'facility_unavailable';
            } elseif ($slot['status'] === 'available'
                && Carbon::parse($date->toDateString().' '.$slot['start_time'], 'Asia/Manila')->lte(now('Asia/Manila'))) {
                $slot['status'] = 'past';
            }

            return $slot;
        });
    }

    public static function acceptedReservations(int $facilityId, Carbon $start, Carbon $end, ?string $barangay = null, ?int $exceptReservationId = null): Collection
    {
        $facility = Facility::query()->findOrFail($facilityId);
        if ($barangay !== null && $facility->barangay !== $barangay) {
            return collect();
        }

        return Reservation::query()->forFacility($facility)
            ->whereBetween('reservation_date', [$start->copy()->subDays(2)->toDateString(), $end->toDateString()])
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->when($exceptReservationId !== null, fn ($query) => $query->where('id', '!=', $exceptReservationId))
            ->select(['id', 'facility_id', 'barangay', 'reservation_date', 'start_time', 'end_time', 'status', 'quantity'])
            ->get()
            ->filter(fn (Reservation $reservation) => $reservation->period()->allocationOverlaps($start->copy()->startOfDay(), $end->copy()->startOfDay()->addDay(), $facility->isEquipment()))
            ->values();
    }

    private static function blockingSchedules(int $facilityId, Carbon $start, Carbon $end, ?string $barangay): Collection
    {
        return self::acceptedReservations($facilityId, $start, $end, $barangay)->concat(
            OfficialUseScheduling::schedules($facilityId, $start->copy()->startOfDay(), $end->copy()->startOfDay()->addDay(), $barangay)
        );
    }

    public static function displayStatus(Reservation $reservation, ?Carbon $now = null): string
    {
        $now ??= now();

        return $reservation->status === 'accepted' && $reservation->period()->contains($now)
            ? 'in_use'
            : 'booked';
    }

    public static function calendarEvents(int $facilityId, Carbon $month, bool $showOfficialDetails = false): Collection
    {
        $facility = Facility::findOrFail($facilityId);

        return self::acceptedReservations(
            $facilityId,
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth()
        )->flatMap(function (Reservation $reservation) use ($month, $facility) {
            $displayStatus = self::displayStatus($reservation);
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
                    // Keep the actual start for read-only cards; start still identifies the calendar day.
                    'original_start' => $period->start->copy()->setTimezone('Asia/Manila')->format('c'),
                    'end' => $period->end->format('Y-m-d\TH:i:s'),
                    'note' => $continued ? 'Continued from '.$period->start->format('M j, Y') : $period->endDateLabel(),
                    'color' => 'red',
                    'event_type' => 'reservation',
                    ...($facility->isEquipment() ? [
                        'quantity' => $reservation->quantity,
                        'total_quantity' => $facility->total_quantity,
                        'whole_resource' => $reservation->quantity === null,
                        'effective_end' => $period->allocationEnd(true)->format('M j, Y g:i A'),
                    ] : []),
                ];
            }

            return $events;
        })->concat(OfficialUseScheduling::schedules($facilityId, $month->copy()->startOfMonth(), $month->copy()->startOfMonth()->addMonth())
            ->flatMap(function (OfficialUse $use) use ($month, $showOfficialDetails, $facility) {
                $period = $use->period();
                $events = [];
                for ($day = $period->start->copy()->startOfDay(); $day->lt($period->end); $day->addDay()) {
                    if (! $day->isSameMonth($month)) {
                        continue;
                    }
                    $continued = ! $day->isSameDay($period->start);
                    $events[] = [
                        'title' => 'Official Use', 'event_type' => 'official_use',
                        'start' => ($continued ? $day : $period->start)->format('Y-m-d\TH:i:s'),
                        'original_start' => $period->start->copy()->setTimezone('Asia/Manila')->format('c'),
                        'end' => $period->end->format('Y-m-d\TH:i:s'),
                        'note' => $continued ? 'Continued from '.$period->start->format('M j, Y') : $period->endDateLabel(),
                        'color' => 'blue',
                        ...($facility->isEquipment() ? [
                            'quantity' => $use->quantity,
                            'total_quantity' => $facility->total_quantity,
                            'whole_resource' => $use->quantity === null,
                            'effective_end' => $period->allocationEnd(true)->format('M j, Y g:i A'),
                        ] : []),
                        ...($showOfficialDetails ? ['purpose' => $use->purpose, 'status' => $use->status] : []),
                    ];
                }

                return $events;
            }))->sortBy('start')->values();
    }

    private static function slotStatusesForReservations(Collection $reservations, Carbon $date, Facility $facility): Collection
    {
        return collect(self::dailySlots())->map(function (array $slot) use ($reservations, $date, $facility) {
            [$startTime, $endTime] = $slot;
            $slotPeriod = new ReservationPeriod($date->toDateString(), $startTime, $endTime);
            $acceptedReservations = $reservations->filter(function ($reservation) use ($slotPeriod, $facility) {
                return $reservation->period()->allocationOverlaps($slotPeriod->start, $slotPeriod->allocationEnd($facility->isEquipment()), $facility->isEquipment());
            });

            $status = match (true) {
                $acceptedReservations->isEmpty() => 'available',
                $acceptedReservations->contains(
                    fn ($reservation) => $reservation instanceof Reservation && self::displayStatus($reservation) === 'in_use'
                ) => 'in_use',
                default => 'booked',
            };
            $availableQuantity = null;
            $allocatedQuantity = null;
            if ($facility->isEquipment()) {
                $allocatedQuantity = self::peakAllocation($facility, $acceptedReservations, $slotPeriod->start, $slotPeriod->allocationEnd(true));
                $availableQuantity = max(0, ($facility->usableQuantity() ?? 0) - $allocatedQuantity);
                $status = $facility->total_quantity === null ? 'facility_unavailable' : ($availableQuantity > 0 ? 'available' : 'booked');
            }

            return [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'end_next_day' => $endTime < $startTime,
                'label' => self::formatTime($startTime).' - '.self::formatTime($endTime),
                'status' => $status,
                'available_quantity' => $availableQuantity,
                'allocated_quantity' => $allocatedQuantity,
            ];
        });
    }

    private static function formatTime(string $time): string
    {
        return Carbon::createFromFormat('H:i', $time)->format('g:i A');
    }
}
