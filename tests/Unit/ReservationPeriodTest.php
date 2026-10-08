<?php

namespace Tests\Unit;

use App\Support\ReservationPeriod;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReservationPeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Manila']);
    }

    public function test_same_day_period_uses_application_timezone_and_has_no_end_date_label(): void
    {
        $period = new ReservationPeriod('2026-10-10', '09:00', '10:30');
        $this->assertTrue($period->isValid());
        $this->assertSame(90, $period->durationMinutes());
        $this->assertSame('2026-10-10T09:00:00+08:00', $period->start->toIso8601String());
        $this->assertSame('2026-10-10T10:30:00+08:00', $period->end->toIso8601String());
        $this->assertSame('Asia/Manila', $period->start->timezoneName);
        $this->assertNull($period->endDateLabel());
        config(['app.timezone' => 'UTC']);
        $utc = new ReservationPeriod('2026-10-10', '09:00', '10:30');
        $this->assertSame('2026-10-10T09:00:00+00:00', $utc->start->toIso8601String());
        $this->assertSame(90, $utc->durationMinutes());
    }

    public function test_overnight_period_rolls_to_next_day_across_month_year_and_leap_day(): void
    {
        foreach ([
            ['2026-10-31', '2026-11-01', 'Ends Nov 1, 2026'],
            ['2026-12-31', '2027-01-01', 'Ends Jan 1, 2027'],
            ['2028-02-28', '2028-02-29', 'Ends Feb 29, 2028'],
        ] as [$date, $endDate, $label]) {
            $period = new ReservationPeriod($date, '23:30', '00:30');
            $this->assertTrue($period->isValid());
            $this->assertSame($endDate, $period->end->toDateString());
            $this->assertSame(60, $period->durationMinutes());
            $this->assertSame($label, $period->endDateLabel());
        }
    }

    public function test_equal_times_are_invalid_rather_than_twenty_four_hours(): void
    {
        $period = new ReservationPeriod('2026-10-10', '09:00', '09:00');
        $this->assertFalse($period->isValid());
        $this->assertSame(0, $period->durationMinutes());
        $this->assertNull($period->endDateLabel());
        $this->assertFalse($period->contains(Carbon::parse('2026-10-10 09:00', 'Asia/Manila')));
        $this->assertFalse($period->overlaps(Carbon::parse('2026-10-10 08:00', 'Asia/Manila'), Carbon::parse('2026-10-10 10:00', 'Asia/Manila')));
    }

    public function test_seconds_are_truncated_to_whole_duration_minutes(): void
    {
        $period = new ReservationPeriod('2026-10-10', '09:00:30', '09:02:29');
        $this->assertTrue($period->isValid());
        $this->assertSame(1, $period->durationMinutes());
    }

    public function test_containment_includes_start_excludes_end_and_compares_instants_across_timezones(): void
    {
        $period = new ReservationPeriod('2026-10-10', '23:30', '00:30');
        foreach ([
            ['2026-10-10 23:29:59', false], ['2026-10-10 23:30:00', true],
            ['2026-10-11 00:00:00', true], ['2026-10-11 00:29:59', true], ['2026-10-11 00:30:00', false],
        ] as [$time, $expected]) {
            $this->assertSame($expected, $period->contains(Carbon::parse($time, 'Asia/Manila')));
        }
        $this->assertTrue($period->contains(Carbon::parse('2026-10-10 16:00:00', 'UTC')));
    }

    public function test_overlaps_are_strict_with_adjacency_allowed_and_inputs_unchanged(): void
    {
        $period = new ReservationPeriod('2026-10-10', '23:30', '00:30');
        foreach ([
            ['2026-10-10 22:00', '2026-10-10 23:30', false],
            ['2026-10-11 00:30', '2026-10-11 01:30', false],
            ['2026-10-10 23:00', '2026-10-11 00:00', true],
            ['2026-10-11 00:00', '2026-10-11 01:00', true],
            ['2026-10-10 23:45', '2026-10-11 00:15', true],
            ['2026-10-10 22:00', '2026-10-11 02:00', true],
        ] as [$start, $end, $expected]) {
            $start = Carbon::parse($start, 'Asia/Manila');
            $end = Carbon::parse($end, 'Asia/Manila');
            $before = [$start->toIso8601String(), $end->toIso8601String(), $period->start->toIso8601String(), $period->end->toIso8601String()];
            $this->assertSame($expected, $period->overlaps($start, $end));
            $this->assertSame($before, [$start->toIso8601String(), $end->toIso8601String(), $period->start->toIso8601String(), $period->end->toIso8601String()]);
        }
    }
}
