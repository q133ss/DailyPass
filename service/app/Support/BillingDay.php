<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class BillingDay
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    public static function fromDateString(string $date): CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::timezone());

        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Invalid billing date, expected YYYY-MM-DD');
        }

        return $day->startOfDay();
    }

    public static function timezone(): string
    {
        return (string) config('billing.timezone', 'UTC');
    }

    public static function endOfDay(CarbonImmutable $day): CarbonImmutable
    {
        return $day->endOfDay();
    }
}
