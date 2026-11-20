<?php

namespace Tests\Feature;

use App\Enums\ChargeAttemptStatus;
use App\Enums\ChargeOperationStatus;
use App\Enums\SubscriberStatus;
use App\Models\ChargeOperation;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;
use Tests\Support\MockPlatform;
use Tests\TestCase;

class BillingTest extends TestCase
{
    public function test_successful_charge_records_operation_attempt_and_receipt(): void
    {
        $subscriber = $this->subscriber();

        $this->runBilling();

        $operation = ChargeOperation::with('attempts')->firstOrFail();
        $this->assertSame(ChargeOperationStatus::Succeeded, $operation->status);
        $this->assertSame('2026-11-20', $operation->billing_day->format('Y-m-d'));
        $this->assertSame('DP-20261120-'.$subscriber->id, $operation->external_trx_id);
        $this->assertSame('5.00', $operation->amount);
        $this->assertSame('XTS', $operation->currency);
        $this->assertNotNull($operation->reference_id);
        $this->assertNotNull($operation->receipt_sent_at);
        $this->assertSame(1, $operation->attempts_count);
        $this->assertSame(ChargeAttemptStatus::Succeeded, $operation->attempts[0]->status);

        $state = MockPlatform::state();
        $this->assertCount(1, $state['charges']);
        $this->assertCount(1, $state['debitRequests']);
        $this->assertSame('DP-20261120-'.$subscriber->id, $state['debitRequests'][0]['externalTrxId']);
        $this->assertSame('5.00', $state['debitRequests'][0]['amount']);
        $this->assertSame($operation->reference_id, $state['charges'][0]['referenceId']);

        $receipts = SmsMessage::where('kind', 'RECEIPT')->get();
        $this->assertCount(1, $receipts);
        $this->assertStringContainsString((string) $operation->reference_id, $receipts[0]->message);
        $this->assertStringContainsString('5.00 XTS', $receipts[0]->message);
    }

    public function test_running_billing_twice_same_day_charges_once(): void
    {
        $this->subscriber();

        $this->runBilling();
        $this->runBilling();

        $state = MockPlatform::state();
        $this->assertCount(1, $state['charges']);
        $this->assertCount(1, $state['debitRequests']);
        $this->assertSame(1, ChargeOperation::count());
        $this->assertSame(1, ChargeOperation::firstOrFail()->attempts_count);
        $this->assertSame(1, SmsMessage::where('kind', 'RECEIPT')->count());
    }

    public function test_duplicate_registered_webhook_does_not_cause_extra_charge(): void
    {
        $this->postJson('/api/subscriptions', ['msisdn' => 'tel:1000000001'])->assertOk();
        $this->runBilling();

        $payload = [
            'applicationId' => 'APP_000123',
            'subscriberId' => 'tel:1000000001',
            'frequency' => 'DAILY',
            'status' => 'REGISTERED',
            'version' => '1.0',
            'timeStamp' => '2026-11-20T12:05:00+00:00',
        ];
        $this->postJson('/api/webhooks/subscription', $payload)->assertOk();
        $this->postJson('/api/webhooks/subscription', $payload)->assertOk();
        $this->runBilling();

        $this->assertDatabaseCount('platform_events', 1);
        $this->assertCount(1, MockPlatform::state()['charges']);
        $this->assertSame(1, ChargeOperation::firstOrFail()->attempts_count);
    }

    public function test_insufficient_balance_is_retried_with_same_external_trx_id(): void
    {
        $subscriber = $this->subscriber();
        MockPlatform::queueDebit('E1326');

        $this->runBilling();

        $operation = ChargeOperation::with('attempts')->firstOrFail();
        $this->assertSame(ChargeOperationStatus::RetryScheduled, $operation->status);
        $this->assertSame('E1326', $operation->last_status_code);
        $this->assertSame(1, $operation->attempts_count);
        $this->assertSame(ChargeAttemptStatus::FailedRetryable, $operation->attempts[0]->status);
        $this->assertTrue($operation->next_retry_at->equalTo(CarbonImmutable::now()->addSeconds(60)));
        $this->assertNull($operation->reference_id);
        $this->assertCount(0, MockPlatform::state()['charges']);
        $this->assertSame(0, SmsMessage::where('kind', 'RECEIPT')->count());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $this->runBilling();

        $operation->refresh();
        $this->assertSame(ChargeOperationStatus::Succeeded, $operation->status);
        $this->assertSame(2, $operation->attempts_count);
        $this->assertNotNull($operation->reference_id);

        $state = MockPlatform::state();
        $this->assertCount(1, $state['charges']);
        $this->assertCount(2, $state['debitRequests']);
        $this->assertSame(
            $state['debitRequests'][0]['externalTrxId'],
            $state['debitRequests'][1]['externalTrxId'],
        );
        $this->assertSame('DP-20261120-'.$subscriber->id, $state['debitRequests'][1]['externalTrxId']);
        $this->assertSame(1, SmsMessage::where('kind', 'RECEIPT')->count());
    }

    public function test_per_second_limit_is_classified_as_retryable(): void
    {
        $this->subscriber();
        MockPlatform::queueDebit('E1318');

        $this->runBilling();

        $operation = ChargeOperation::firstOrFail();
        $this->assertSame(ChargeOperationStatus::RetryScheduled, $operation->status);
        $this->assertSame('E1318', $operation->last_status_code);
        $this->assertCount(0, MockPlatform::state()['charges']);
    }

    public function test_permanent_error_suspends_subscriber_and_does_not_retry(): void
    {
        $subscriber = $this->subscriber();
        MockPlatform::queueDebit('E1308');

        $this->runBilling();

        $operation = ChargeOperation::with('attempts')->firstOrFail();
        $this->assertSame(ChargeOperationStatus::FailedPermanent, $operation->status);
        $this->assertSame('E1308', $operation->last_status_code);
        $this->assertSame(1, $operation->attempts_count);
        $this->assertSame(ChargeAttemptStatus::FailedPermanent, $operation->attempts[0]->status);

        $subscriber->refresh();
        $this->assertSame(SubscriberStatus::Suspended, $subscriber->status);
        $this->assertNotNull($subscriber->suspended_at);
        $this->assertCount(0, MockPlatform::state()['charges']);

        $this->runBilling();
        $this->assertCount(1, MockPlatform::state()['debitRequests']);
    }

    public function test_lost_success_response_is_recovered_by_e1337_with_original_data(): void
    {
        config(['services.platform.timeout_seconds' => 0.4]);
        $this->subscriber();
        MockPlatform::queueDebit('S1000', 900);

        $this->runBilling();

        $operation = ChargeOperation::with('attempts')->firstOrFail();
        $this->assertSame(ChargeOperationStatus::RetryScheduled, $operation->status);
        $this->assertSame(ChargeAttemptStatus::Timeout, $operation->attempts[0]->status);
        $this->assertNull($operation->reference_id);
        $this->assertNull($operation->receipt_sent_at);

        $state = MockPlatform::state();
        $this->assertCount(1, $state['charges']);
        $this->assertSame(0, SmsMessage::where('kind', 'RECEIPT')->count());

        config(['services.platform.timeout_seconds' => 3.0]);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $this->runBilling();

        $operation->refresh();
        $this->assertSame(ChargeOperationStatus::Succeeded, $operation->status);
        $this->assertSame(2, $operation->attempts_count);
        $this->assertSame('E1337', $operation->last_status_code);
        $this->assertSame($state['charges'][0]['referenceId'], $operation->reference_id);
        $this->assertNotNull($operation->receipt_sent_at);

        $stateAfter = MockPlatform::state();
        $this->assertCount(1, $stateAfter['charges']);
        $this->assertCount(2, $stateAfter['debitRequests']);
        $this->assertSame(
            $stateAfter['debitRequests'][0]['externalTrxId'],
            $stateAfter['debitRequests'][1]['externalTrxId'],
        );
        $this->assertSame(1, SmsMessage::where('kind', 'RECEIPT')->count());
    }

    public function test_e1337_without_original_data_is_unknown_and_never_gets_a_fake_receipt(): void
    {
        config(['services.platform.timeout_seconds' => 0.4]);
        $this->subscriber();
        MockPlatform::configure(['duplicateDetails' => false]);
        MockPlatform::queueDebit('S1000', 900);

        $this->runBilling();

        config(['services.platform.timeout_seconds' => 3.0]);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $this->runBilling();

        $operation = ChargeOperation::with('attempts')->firstOrFail();
        $this->assertSame(ChargeOperationStatus::Unknown, $operation->status);
        $this->assertNull($operation->reference_id);
        $this->assertNull($operation->receipt_sent_at);
        $this->assertSame(2, $operation->attempts_count);
        $this->assertSame(ChargeAttemptStatus::Unknown, $operation->attempts[1]->status);
        $this->assertCount(1, MockPlatform::state()['charges']);
        $this->assertSame(0, SmsMessage::where('kind', 'RECEIPT')->count());
        $this->assertStringContainsString('without original transaction data', (string) $operation->last_status_detail);
    }

    public function test_retry_does_not_cross_the_billing_day_boundary(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-19 23:50:00', 'UTC'));
        $this->subscriber();
        MockPlatform::queueDebit('E1326');

        $this->runBilling('2026-11-19');
        $old = ChargeOperation::firstOrFail();
        $this->assertSame(ChargeOperationStatus::RetryScheduled, $old->status);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 00:10:00', 'UTC'));
        $this->runBilling('2026-11-20');

        $old->refresh();
        $this->assertSame(ChargeOperationStatus::Expired, $old->status);
        $this->assertSame('DAY_BOUNDARY', $old->last_status_code);

        $today = ChargeOperation::whereDate('billing_day', '2026-11-20')->firstOrFail();
        $this->assertSame(ChargeOperationStatus::Succeeded, $today->status);
        $this->assertSame(1, $today->attempts_count);

        $state = MockPlatform::state();
        $this->assertCount(1, $state['charges']);
        $this->assertCount(2, $state['debitRequests']);
    }

    public function test_failure_near_midnight_closes_retry_window_immediately(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 23:59:30', 'UTC'));
        $this->subscriber();
        MockPlatform::queueDebit('E1326');

        $this->runBilling();

        $operation = ChargeOperation::firstOrFail();
        $this->assertSame(ChargeOperationStatus::Expired, $operation->status);
        $this->assertSame('Retry window closed at the billing day boundary', $operation->last_status_detail);
        $this->assertCount(0, MockPlatform::state()['charges']);
    }

    public function test_stop_before_billing_creates_no_operation(): void
    {
        Subscriber::factory()->inactive()->create([
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:1000000001',
            'stopped_at' => now(),
        ]);

        $this->runBilling();

        $this->assertSame(0, ChargeOperation::count());
        $this->assertCount(0, MockPlatform::state()['debitRequests']);
    }

    public function test_stop_suppresses_a_scheduled_retry(): void
    {
        $subscriber = $this->subscriber();
        MockPlatform::queueDebit('E1326');
        $this->runBilling();

        $subscriber->forceFill([
            'status' => SubscriberStatus::Inactive,
            'stopped_at' => CarbonImmutable::now(),
        ])->save();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $this->runBilling();

        $operation = ChargeOperation::firstOrFail();
        $this->assertSame(ChargeOperationStatus::Cancelled, $operation->status);
        $this->assertSame(1, $operation->attempts_count);
        $this->assertCount(0, MockPlatform::state()['charges']);
        $this->assertCount(1, MockPlatform::state()['debitRequests']);
    }

    public function test_concurrent_billing_runs_charge_only_once(): void
    {
        $this->subscriber();
        MockPlatform::queueDebit('S1000', 900);
        $environment = MockPlatform::artisanEnvironment();

        $processes = [
            new Process([PHP_BINARY, 'artisan', 'billing:run', '--date=2026-11-20'], base_path(), $environment),
            new Process([PHP_BINARY, 'artisan', 'billing:run', '--date=2026-11-20'], base_path(), $environment),
        ];

        foreach ($processes as $process) {
            $process->setTimeout(30);
            $process->start();
        }

        while (array_filter($processes, static fn (Process $process): bool => $process->isRunning()) !== []) {
            usleep(100_000);
        }

        foreach ($processes as $process) {
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        }

        $state = MockPlatform::state();
        $this->assertCount(1, $state['charges']);
        $this->assertCount(1, $state['debitRequests']);
        $this->assertSame(1, ChargeOperation::count());
        $this->assertSame(1, ChargeOperation::firstOrFail()->attempts_count);
        $this->assertSame(ChargeOperationStatus::Succeeded, ChargeOperation::firstOrFail()->status);
    }

    public function test_invalid_amount_configuration_stops_before_any_charge(): void
    {
        $this->subscriber();
        config(['billing.daily_amount' => '5']);

        $this->artisan('billing:run', ['--date' => '2026-11-20'])->assertFailed();

        $this->assertSame(0, ChargeOperation::count());
        $this->assertCount(0, MockPlatform::state()['debitRequests']);
    }

    private function subscriber(): Subscriber
    {
        return Subscriber::factory()->create([
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:1000000001',
        ]);
    }

    private function runBilling(string $date = '2026-11-20'): void
    {
        $this->artisan('billing:run', ['--date' => $date])->assertSuccessful();
    }
}
