<?php

namespace App\Support;

use App\Models\Facility;
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

    public static function monthCalendar(int $facilityId, Carbon $month, ?string $barangay = null): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $reservations = self::acceptedReservations($facilityId, $start, $end, $barangay);
        $today = today();

        return collect(range(1, $end->day))->map(function (int $day) use ($start, $reservations, $today) {
            $date = $start->copy()->day($day);
            $dateReservations = $reservations->where('reservation_date', $date->toDateString());
            $slotStatuses = self::slotStatusesForReservations($dateReservations);
            $availableSlots = $slotStatuses->where('status', 'available')->count();

            $status = match (true) {
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

    public static function daySchedule(int $facilityId, Carbon $date, ?string $barangay = null): Collection
    {
        $reservations = self::acceptedReservations($facilityId, $date, $date, $barangay);

        return self::slotStatusesForReservations($reservations)->map(function (array $slot) use ($date) {
            if ($date->isBefore(today())) {
                $slot['status'] = 'unavailable';
            }

            return $slot;
        });
    }

    public static function acceptedReservations(int $facilityId, Carbon $start, Carbon $end, ?string $barangay = null): Collection
    {
        $facilitySlug = Facility::query()->whereKey($facilityId)->value('slug');

        return self::scopeBarangay(Reservation::query(), $barangay)
            ->where(function ($query) use ($facilityId, $facilitySlug) {
                $query->where('facility_id', $facilityId);

                if ($facilitySlug !== null) {
                    $query->orWhere(function ($legacyQuery) use ($facilitySlug) {
                        $legacyQuery->whereNull('facility_id')
                            ->where('facility_slug', $facilitySlug);
                    });
                }
            })
            ->whereBetween('reservation_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->select(['facility_id', 'barangay', 'reservation_date', 'start_time', 'end_time', 'status'])
            ->get();
    }

    public static function displayStatus(Reservation $reservation, ?Carbon $now = null): string
    {
        $now ??= now();
        $timezone = config('app.timezone');
        $start = Carbon::parse($reservation->reservation_date.' '.$reservation->start_time, $timezone);
        $end = Carbon::parse($reservation->reservation_date.' '.$reservation->end_time, $timezone);

        return $now->greaterThanOrEqualTo($start) && $now->lessThan($end)
            ? 'in_use'
            : 'booked';
    }

    private static function scopeBarangay($query, ?string $barangay)
    {
        if ($barangay !== null) {
            $query->where('barangay', $barangay);
        }

        return $query;
    }

    private static function slotStatusesForReservations(Collection $reservations): Collection
    {
        return collect(self::DAILY_SLOTS)->map(function (array $slot) use ($reservations) {
            [$startTime, $endTime] = $slot;
            $acceptedReservations = $reservations->filter(function (Reservation $reservation) use ($startTime, $endTime) {
                return self::normalizeTime($reservation->start_time) < $endTime
                    && self::normalizeTime($reservation->end_time) > $startTime;
            });

            $status = match (true) {
                $acceptedReservations->isEmpty() => 'available',
                $acceptedReservations->contains(
                    fn (Reservation $reservation) => self::displayStatus($reservation) === 'in_use'
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

    private static function normalizeTime(string $time): string
    {
        return Carbon::parse($time)->format('H:i');
    }
}
