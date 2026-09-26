<?php

namespace App\Support;

class PhoneNumber
{
    public static function rules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:16', 'regex:/\A(?:09[0-9]{9}|\+639[0-9]{9})\z/'];
    }

    public static function messages(): array
    {
        return ['phone_number.regex' => 'Enter a Philippine mobile number such as 09171234567 or +639171234567.'];
    }

    public static function normalize(?string $number): ?string
    {
        $number = trim($number ?? '');

        return $number === '' ? null : (str_starts_with($number, '09') ? '+63'.substr($number, 1) : $number);
    }
}
