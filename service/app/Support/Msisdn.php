<?php

namespace App\Support;

final class Msisdn
{
    /**
     * The public opt-in boundary only accepts tel:+digits, as in the API contract.
     * Opaque masked aliases are never accepted here.
     */
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^tel:\d{6,15}$/D', $value) === 1;
    }

    public static function mask(string $value): string
    {
        if (preg_match('/^tel:(\d{6,15})$/D', $value, $matches) !== 1) {
            return 'tel:***';
        }

        $digits = $matches[1];

        return 'tel:'.str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
