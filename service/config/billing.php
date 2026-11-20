<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Billing calendar
    |--------------------------------------------------------------------------
    |
    | The billing day is a calendar day in this timezone. All timestamps are
    | stored in UTC; only the day boundary is interpreted in this zone.
    |
    */

    'timezone' => env('BILLING_TIMEZONE', 'UTC'),

    'daily_amount' => env('BILLING_DAILY_AMOUNT', '5.00'),

    'currency' => env('BILLING_CURRENCY', 'XTS'),

    'max_attempts' => (int) env('BILLING_MAX_ATTEMPTS', 3),

    /*
    | Delay before the next attempt, indexed by the failed attempt number - 1.
    | The last value is reused if more attempts are configured.
    */
    'retry_delays_seconds' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('BILLING_RETRY_DELAYS_SECONDS', '60,300')),
    ), static fn (int $seconds): bool => $seconds >= 0)),

    /*
    | Demo-only mapping from a real MSISDN to the opaque subscriberId sent to
    | the carrier. An identity mapping is used when the key is absent.
    */
    'aliases' => (static function (): array {
        $decoded = json_decode((string) env('BILLING_ALIASES', '{}'), true);

        return is_array($decoded) ? $decoded : [];
    })(),

];
