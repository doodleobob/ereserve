<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FutureReservationStart
{
    public static function validate(string $date, string $time): void
    {
        // Reservation date/time columns store Philippine wall-clock values, not UTC instants.
        $start = Carbon::parse($date, 'Asia/Manila')->setTimezone('Asia/Manila')->startOfDay()->setTimeFromTimeString($time);
        if (! $start->gt(Carbon::now('Asia/Manila'))) {
            throw ValidationException::withMessages([
                'start_time' => 'The selected reservation start time must be in the future.',
            ]);
        }
    }
}
