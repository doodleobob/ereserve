<?php

namespace App\Support;

use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReservationAvailability
{
    public const BLOCKING_STATUSES = ['pending', 'approved'];

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

    public static function monthCalendar(string $facilitySlug, Carbon $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $reservations = self::blockingReservations($facilitySlug, $start, $end)->get();
        $today = today();

        return collect(range(1, $end->day))->map(function (int $day) use ($start, $reservations, $today) {
            $date = $start->copy()->day($day);
            $dateReservations = $reservations->where('reservation_date', $date->toDateString());
            $slotStatuses = self::slotStatusesForReservations($dateReservations);
            $availableSlots = $slotStatuses->where('status', 'available')->count();

            $status = match (true) {
                $date->lt($today) => 'unavailable',
                $availableSlots === 0 => 'full',
                $availableSlots < count(self::DAILY_SLOTS) => 'partial',
                default => 'available',
            };

            return [
                'date' => $date,
                'status' => $status,
                'available_slots' => $availableSlots,
            ];
        });
    }

    public static function daySchedule(string $facilitySlug, Carbon $date): Collection
    {
        $reservations = self::blockingReservations($facilitySlug, $date, $date)->get();

        return self::slotStatusesForReservations($reservations)->map(function (array $slot) use ($date) {
            if ($date->isBefore(today())) {
                $slot['status'] = 'unavailable';
            }

            return $slot;
        });
    }

    public static function hasConflict(string $facilitySlug, string $date, string $startTime, string $endTime): bool
    {
        return Reservation::query()
            ->where('facility_slug', $facilitySlug)
            ->whereDate('reservation_date', $date)
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->exists();
    }

    private static function blockingReservations(string $facilitySlug, Carbon $start, Carbon $end)
    {
        return Reservation::query()
            ->where('facility_slug', $facilitySlug)
            ->whereBetween('reservation_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->select(['facility_slug', 'reservation_date', 'start_time', 'end_time', 'status']);
    }

    private static function slotStatusesForReservations(Collection $reservations): Collection
    {
        return collect(self::DAILY_SLOTS)->map(function (array $slot) use ($reservations) {
            [$startTime, $endTime] = $slot;
            $isReserved = $reservations->contains(function (Reservation $reservation) use ($startTime, $endTime) {
                return self::normalizeTime($reservation->start_time) < $endTime
                    && self::normalizeTime($reservation->end_time) > $startTime;
            });

            return [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'label' => self::formatTime($startTime).' - '.self::formatTime($endTime),
                'status' => $isReserved ? 'reserved' : 'available',
            ];
        });
    }

    private static function formatTime(string $time): string
    {
        return Carbon::createFromFormat('H:i', $time)->format('g:i A');
    }

    private static function normalizeTime(string $time): string
    {
        return Carbon::parse($time)->format('H:i');
    }
}
