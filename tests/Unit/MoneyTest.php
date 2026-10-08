<?php

namespace Tests\Unit;

use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_valid_decimal_amounts_convert_to_exact_cents_and_canonical_decimals(): void
    {
        foreach ([
            ['0', 0, '0.00'], ['0.01', 1, '0.01'], ['0.1', 10, '0.10'],
            ['1.05', 105, '1.05'], ['001.5', 150, '1.50'], ['1234.56', 123456, '1234.56'],
            ['9999999999.99', 999999999999, '9999999999.99'],
        ] as [$input, $cents, $decimal]) {
            $this->assertSame($cents, Money::cents($input));
            $this->assertSame($decimal, Money::decimal($cents));
        }
    }

    public function test_validation_accepts_current_nonnegative_decimal_forms_and_maximum(): void
    {
        foreach (['0', '0.00', '1', '1.2', '1.23', '001.5', '100.00'] as $amount) {
            $this->assertTrue(Validator::make(['amount' => $amount], ['amount' => Money::rules('100.00')])->passes(), $amount);
        }
    }

    public function test_validation_rejects_missing_null_negative_excess_precision_and_other_numeric_formats(): void
    {
        foreach ([[], ['amount' => null], ['amount' => ''], ['amount' => '-0.01'], ['amount' => '1.234'], ['amount' => '1e2'], ['amount' => '+1'], ['amount' => '.5'], ['amount' => '1.'], ['amount' => '100.01']] as $data) {
            $this->assertTrue(Validator::make($data, ['amount' => Money::rules('100.00')])->fails(), json_encode($data));
        }
    }

    public function test_format_distinguishes_unrecorded_from_zero_and_keeps_currency_grouping_and_rounding(): void
    {
        $this->assertSame('Not recorded', Money::format(null));
        $this->assertSame('₱0.00', Money::format('0'));
        $this->assertSame('₱1,234.50', Money::format('1234.5'));
        $this->assertSame('₱1.24', Money::format('1.235'));
    }

    public function test_reservation_amount_preserves_half_up_minute_rounding_and_null_rates(): void
    {
        foreach ([
            ['0.01', '09:00', '09:29', '0.00'],
            ['0.01', '09:00', '09:30', '0.01'],
            ['0.03', '09:00', '09:10', '0.01'],
            ['100.00', '09:00', '10:30', '150.00'],
            ['100.00', '23:30', '00:30', '100.00'],
            ['0.00', '09:00', '10:00', '0.00'],
            [null, '09:00', '10:00', null],
        ] as [$rate, $start, $end, $amount]) {
            $reservation = new Reservation(['reservation_date' => '2026-10-10', 'start_time' => $start, 'end_time' => $end, 'hourly_rate_snapshot' => $rate]);
            $this->assertSame($amount, $reservation->calculatedAmount());
        }
    }
}
