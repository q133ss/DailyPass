<?php

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\MockPlatform;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.timezone' => 'UTC',
            'billing.daily_amount' => '5.00',
            'billing.currency' => 'XTS',
            'billing.max_attempts' => 3,
            'billing.retry_delays_seconds' => [60, 300],
            'billing.aliases' => [],
            'services.platform.base_url' => env('PLATFORM_BASE_URL', 'http://platform-mock:8080'),
            'services.platform.application_id' => env('PLATFORM_APPLICATION_ID', 'APP_000123'),
            'services.platform.password' => env('PLATFORM_PASSWORD', 'demo-password-not-a-secret'),
            'services.platform.timeout_seconds' => 3.0,
        ]);

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'),
        );

        MockPlatform::reset();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
