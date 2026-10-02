<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReservationAvailability
{
    public const BLOCKING_STATUSES = ['accepted'];

    public const DAILY_SLOTS = [
        ['08:00', '09:00'],
        ['09:00', '10:00'],
        ['10:00', '11:00'],
        ['11:00', '12:00'],
        ['13:00', '14:00'],
        ['14:00', '15:00'],
        ['15:00', '16:00'],
        ['16:00', '17:00'],
    ];

    public static function monthCalendar(int $facilityId, Carbon $month, ?string $barangay = null, bool $facilityAvailable = true): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $reservations = $facilityAvailable
            ? self::blockingSchedules($facilityId, $start, $end, $barangay)
            : collect();
        $today = today();

        return collect(range(1, $end->day))->map(function (int $day) use ($start, $reservations, $today, $facilityAvailable) {
            $date = $start->copy()->day($day);
            $dateReservations = $reservations->filter(fn ($reservation) => $reservation->period()->overlaps($date, $date->copy()->addDay()));
            $slotStatuses = self::slotStatusesForReservations($dateReservations, $date);
            $availableSlots = $slotStatuses->where('status', 'available')->count();

            $status = match (true) {
                ! $facilityAvailable => 'unavailable',
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
            ? self::blockingSchedules($facilityId, $date, $date, $barangay)
            : collect();

        return self::slotStatusesForReservations($reservations, $date)->map(function (array $slot) use ($date, $facilityAvailable) {
            if (! $facilityAvailable) {
                $slot['status'] = 'facility_unavailable';
            } elseif ($date->isBefore(today())) {
                $slot['status'] = 'unavailable';
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
            ->whereBetween('reservation_date', [$start->copy()->subDay()->toDateString(), $end->toDateString()])
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->when($exceptReservationId !== null, fn ($query) => $query->where('id', '!=', $exceptReservationId))
            ->select(['id', 'facility_id', 'barangay', 'reservation_date', 'start_time', 'end_time', 'status'])
            ->get()
            ->filter(fn (Reservation $reservation) => $reservation->period()->overlaps($start->copy()->startOfDay(), $end->copy()->startOfDay()->addDay()))
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
        return self::acceptedReservations(
            $facilityId,
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth()
        )->flatMap(function (Reservation $reservation) use ($month) {
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
                    'end' => $period->end->format('Y-m-d\TH:i:s'),
                    'note' => $continued ? 'Continued from '.$period->start->format('M j, Y') : $period->endDateLabel(),
                    'color' => 'red',
                    'event_type' => 'reservation',
                ];
            }

            return $events;
        })->concat(OfficialUseScheduling::schedules($facilityId, $month->copy()->startOfMonth(), $month->copy()->startOfMonth()->addMonth())
            ->flatMap(function (OfficialUse $use) use ($month, $showOfficialDetails) {
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
                        'end' => $period->end->format('Y-m-d\TH:i:s'),
                        'note' => $continued ? 'Continued from '.$period->start->format('M j, Y') : $period->endDateLabel(),
                        'color' => 'blue',
                        ...($showOfficialDetails ? ['purpose' => $use->purpose, 'status' => $use->status] : []),
                    ];
                }

                return $events;
            }))->sortBy('start')->values();
    }

    private static function slotStatusesForReservations(Collection $reservations, Carbon $date): Collection
    {
        return collect(self::DAILY_SLOTS)->map(function (array $slot) use ($reservations, $date) {
            [$startTime, $endTime] = $slot;
            $slotPeriod = new ReservationPeriod($date->toDateString(), $startTime, $endTime);
            $acceptedReservations = $reservations->filter(function ($reservation) use ($slotPeriod) {
                return $reservation->period()->overlaps($slotPeriod->start, $slotPeriod->end);
            });

            $status = match (true) {
                $acceptedReservations->isEmpty() => 'available',
                $acceptedReservations->contains(
                    fn ($reservation) => $reservation instanceof Reservation && self::displayStatus($reservation) === 'in_use'
                ) => 'in_use',
                default => 'booked',
            };

            return [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'label' => self::formatTime($startTime).' - '.self::formatTime($endTime),
                'status' => $status,
            ];
        });
    }

    private static function formatTime(string $time): string
    {
        return Carbon::createFromFormat('H:i', $time)->format('g:i A');
    }
}
