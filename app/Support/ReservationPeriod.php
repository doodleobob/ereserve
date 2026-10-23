<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class ReservationPeriod
{
    public const EQUIPMENT_BUFFER_MINUTES = 60;

    public readonly Carbon $start;

    public readonly Carbon $end;

    public function __construct(string $date, string $startTime, string $endTime)
    {
        $this->start = Carbon::parse($date.' '.$startTime, config('app.timezone'));
        $end = Carbon::parse($date.' '.$endTime, config('app.timezone'));
        $this->end = $end->lt($this->start) ? $end->addDay() : $end;
    }

    public function isValid(): bool
    {
        return $this->end->gt($this->start);
    }

    public function durationMinutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }

    public function overlaps(Carbon $start, Carbon $end): bool
    {
        return $this->isValid() && $this->start->lt($end) && $this->end->gt($start);
    }

    public function allocationEnd(bool $equipment): Carbon
    {
        return $this->end->copy()->addMinutes($equipment ? self::EQUIPMENT_BUFFER_MINUTES : 0);
    }

    public function allocationOverlaps(Carbon $start, Carbon $end, bool $equipment): bool
    {
        return $this->isValid() && $this->start->lt($end) && $this->allocationEnd($equipment)->gt($start);
    }

    public function contains(Carbon $time): bool
    {
        return $time->gte($this->start) && $time->lt($this->end);
    }

    public function endDateLabel(): ?string
    {
        return $this->start->isSameDay($this->end) ? null : 'Ends '.$this->end->format('M j, Y');
    }
}
