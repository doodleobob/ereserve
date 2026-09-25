<?php

namespace App\Support;

class Money
{
    public static function rules(string $maximum): array
    {
        return ['required', 'numeric', 'min:0', 'max:'.$maximum, 'regex:/^\d+(\.\d{1,2})?$/'];
    }

    public static function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(?string $amount): string
    {
        return $amount === null ? 'Not recorded' : '₱'.number_format((float) $amount, 2);
    }
}
