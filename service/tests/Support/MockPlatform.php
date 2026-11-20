<?php

namespace Tests\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class MockPlatform
{
    public static function baseUrl(): string
    {
        return rtrim((string) config('services.platform.base_url'), '/');
    }

    public static function reset(): void
    {
        self::client()->post(self::baseUrl().'/mock/reset')->throw();
    }

    public static function queueDebit(string $statusCode, int $delayMs = 0, int $count = 1): void
    {
        self::client()->post(self::baseUrl().'/mock/debit-response', [
            'statusCode' => $statusCode,
            'delayMs' => $delayMs,
            'count' => $count,
        ])->throw();
    }

    /** @param array<string, mixed> $config */
    public static function configure(array $config): void
    {
        self::client()->post(self::baseUrl().'/mock/config', $config)->throw();
    }

    /** @return array<string, mixed> */
    public static function state(): array
    {
        return self::client()->get(self::baseUrl().'/mock/state')->throw()->json();
    }

    /** @param array<string, mixed> $overrides */
    public static function callback(array $overrides = []): void
    {
        self::client()->post(self::baseUrl().'/mock/callback', array_merge([
            'subscriberId' => 'tel:1000000001',
            'status' => 'REGISTERED',
        ], $overrides))->throw();
    }

    public static function client(): PendingRequest
    {
        return Http::timeout(15)->acceptJson()->asJson();
    }

    /** @return array<string, string> */
    public static function artisanEnvironment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) env('APP_KEY'),
            'LOG_CHANNEL' => 'stderr',
            'LOG_LEVEL' => 'debug',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) env('DB_HOST', 'db'),
            'DB_PORT' => (string) env('DB_PORT', '5432'),
            'DB_DATABASE' => (string) env('DB_DATABASE', 'dailypass_test'),
            'DB_USERNAME' => (string) env('DB_USERNAME', 'dailypass'),
            'DB_PASSWORD' => (string) env('DB_PASSWORD', 'dailypass'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'BILLING_TIMEZONE' => 'UTC',
            'BILLING_DAILY_AMOUNT' => '5.00',
            'BILLING_CURRENCY' => 'XTS',
            'BILLING_MAX_ATTEMPTS' => '3',
            'BILLING_RETRY_DELAYS_SECONDS' => '60,300',
            'BILLING_ALIASES' => '{}',
            'PLATFORM_BASE_URL' => self::baseUrl(),
            'PLATFORM_APPLICATION_ID' => (string) config('services.platform.application_id'),
            'PLATFORM_PASSWORD' => (string) config('services.platform.password'),
            'PLATFORM_TIMEOUT_SECONDS' => '3',
        ];
    }
}
