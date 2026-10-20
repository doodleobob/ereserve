<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Policy for newly selected schedules. Stored periods retain their historical semantics. */
class HourlyBooking
{
    public static function rules(string $dateKey = 'reservation_date'): array
    {
        return [
            $dateKey => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'end_next_day' => ['sometimes', 'boolean'],
        ];
    }

    public static function unchanged(array $data, ReservationPeriod $existing, string $dateKey): bool
    {
        return $data[$dateKey] === $existing->start->toDateString()
            && $data['start_time'] === $existing->start->format('H:i')
            && $data['end_time'] === $existing->end->format('H:i')
            && (! array_key_exists('end_next_day', $data)
                || (bool) $data['end_next_day'] === ! $existing->start->isSameDay($existing->end));
    }

    public static function period(array $data, string $dateKey = 'reservation_date', ?ReservationPeriod $existing = null): ReservationPeriod
    {
        Validator::make($data, self::rules($dateKey))->validate();
        if ($existing && self::unchanged($data, $existing, $dateKey)) {
            return $existing;
        }
        if ($data[$dateKey] < now('Asia/Manila')->toDateString()) {
            throw ValidationException::withMessages([$dateKey => 'The reservation date cannot be in the past.']);
        }
        FutureReservationStart::validate($data[$dateKey], $data['start_time']);
        foreach (['start_time' => 'start', 'end_time' => 'end'] as $field => $label) {
            if (substr($data[$field], 3) !== '00') {
                throw ValidationException::withMessages([$field => "Please select a whole-hour {$label} time."]);
            }
        }
        $overnight = (bool) ($data['end_next_day'] ?? false);
        // The existing storage supports same-day periods and an earlier clock time on the next day.
        // Equal times and multi-day periods were never supported; do not invent a new duration cap.
        if ((! $overnight && $data['end_time'] <= $data['start_time'])
            || ($overnight && $data['end_time'] >= $data['start_time'])) {
            throw ValidationException::withMessages(['end_time' => $overnight
                ? 'For an overnight booking, select an earlier end time on the following day. Start and end times must be different.'
                : 'End time must be later than start time. Select Next day for an overnight booking.']);
        }

        return new ReservationPeriod($data[$dateKey], $data['start_time'], $data['end_time']);
    }

    /** Never rewrite historical seconds or minute-based times during an unrelated edit. */
    public static function preserveUnchangedTimes(array &$data, ReservationPeriod $existing, string $dateKey = 'reservation_date'): void
    {
        if (self::unchanged($data, $existing, $dateKey)) {
            unset($data[$dateKey], $data['start_time'], $data['end_time']);
        }
        unset($data['end_next_day']);
    }
}
