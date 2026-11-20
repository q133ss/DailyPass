<?php

namespace Tests\Feature;

use App\Support\BillingDay;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BillingDayTest extends TestCase
{
    public function test_billing_day_is_derived_in_configured_timezone(): void
    {
        config(['billing.timezone' => 'Asia/Karachi']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 18:59:00', 'UTC'));
        $this->assertSame('2026-11-20', BillingDay::now()->format('Y-m-d'));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 19:00:00', 'UTC'));
        $this->assertSame('2026-11-21', BillingDay::now()->format('Y-m-d'));
    }

    public function test_end_of_billing_day_uses_the_same_timezone(): void
    {
        config(['billing.timezone' => 'Asia/Karachi']);

        $day = BillingDay::fromDateString('2026-11-20');

        $this->assertSame('2026-11-20 23:59:59', $day->endOfDay()->format('Y-m-d H:i:s'));
        $this->assertSame('+05:00', $day->endOfDay()->format('P'));
    }
}
