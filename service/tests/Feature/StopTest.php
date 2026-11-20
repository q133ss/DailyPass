<?php

namespace Tests\Feature;

use App\Enums\ChargeOperationStatus;
use App\Enums\SubscriberStatus;
use App\Models\ChargeOperation;
use App\Models\Subscriber;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;
use Tests\Support\MockPlatform;
use Tests\TestCase;

class StopTest extends TestCase
{
    public function test_stop_opts_out_sends_goodbye_and_blocks_future_charges(): void
    {
        $subscriber = $this->subscriber();

        $this->sendMo('STOP')->assertOk()->assertJsonPath('statusCode', 'S1000');

        $subscriber->refresh();
        $this->assertSame(SubscriberStatus::Inactive, $subscriber->status);
        $this->assertNotNull($subscriber->stopped_at);
        $this->assertSame('CONFIRMED', $subscriber->opt_out_status);
        $this->assertSame(1, $subscriber->opt_out_attempts);

        $state = MockPlatform::state();
        $this->assertCount(1, $state['subscriptionRequests']);
        $this->assertSame('0', $state['subscriptionRequests'][0]['action']);
        $this->assertCount(1, $state['sms']);
        $this->assertStringContainsString('unsubscribed', $state['sms'][0]['message']);

        $this->artisan('billing:run', ['--date' => '2026-11-20'])->assertSuccessful();
        $this->assertSame(0, ChargeOperation::count());
        $this->assertCount(0, MockPlatform::state()['debitRequests']);
    }

    public function test_duplicate_stop_is_idempotent_and_does_not_resend_goodbye(): void
    {
        $subscriber = $this->subscriber();

        $this->sendMo('STOP')->assertOk();
        $this->sendMo('stop')->assertOk();

        $state = MockPlatform::state();
        $this->assertCount(1, $state['subscriptionRequests']);
        $this->assertCount(1, $state['sms']);
        $this->assertSame(1, $subscriber->refresh()->opt_out_attempts);
        $this->assertDatabaseCount('sms_messages', 3);
    }

    public function test_non_stop_mo_message_is_logged_without_opt_out(): void
    {
        $subscriber = $this->subscriber();

        $this->sendMo('HELLO')->assertOk();

        $this->assertSame(SubscriberStatus::Active, $subscriber->refresh()->status);
        $this->assertCount(0, MockPlatform::state()['subscriptionRequests']);
        $this->assertDatabaseHas('sms_messages', [
            'subscriber_id' => $subscriber->id,
            'direction' => 'MO',
            'message' => 'HELLO',
        ]);
    }

    public function test_unknown_subscriber_stop_is_acknowledged_without_side_effects(): void
    {
        $this->postJson('/api/webhooks/sms', [
            'applicationId' => 'APP_000123',
            'sourceAddress' => 'tel:1999999999',
            'message' => 'STOP',
            'encoding' => '0',
            'version' => '1.0',
        ])->assertOk()->assertJsonPath('statusCode', 'S1000');

        $this->assertDatabaseCount('subscribers', 0);
        $this->assertCount(0, MockPlatform::state()['subscriptionRequests']);
    }

    public function test_opt_out_timeout_keeps_local_state_safe_and_can_be_reconciled(): void
    {
        $subscriber = $this->subscriber();
        config(['services.platform.timeout_seconds' => 0.4]);
        MockPlatform::configure(['optoutDelayMs' => 900]);

        $this->sendMo('STOP')->assertOk();

        $subscriber->refresh();
        $this->assertSame(SubscriberStatus::Inactive, $subscriber->status);
        $this->assertNotNull($subscriber->stopped_at);
        $this->assertSame('UNCONFIRMED', $subscriber->opt_out_status);
        $this->assertSame(1, $subscriber->opt_out_attempts);
        $this->assertCount(1, MockPlatform::state()['sms']);

        $this->artisan('billing:run', ['--date' => '2026-11-20'])->assertSuccessful();
        $this->assertCount(0, MockPlatform::state()['debitRequests']);

        config(['services.platform.timeout_seconds' => 3.0]);
        MockPlatform::configure(['optoutDelayMs' => 0]);
        $this->artisan('subscriptions:retry-optouts')->assertSuccessful();

        $subscriber->refresh();
        $this->assertSame('CONFIRMED', $subscriber->opt_out_status);
        $this->assertSame(2, $subscriber->opt_out_attempts);
        $this->assertCount(2, MockPlatform::state()['subscriptionRequests']);
    }

    public function test_stop_during_inflight_success_records_actual_charge_once(): void
    {
        $subscriber = $this->subscriber();
        config(['services.platform.timeout_seconds' => 5.0]);
        MockPlatform::queueDebit('S1000', 1500);

        $process = $this->startBillingProcess();
        $this->waitForDebitRequest();
        $this->sendMo('STOP')->assertOk();
        $process->wait();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(SubscriberStatus::Inactive, $subscriber->refresh()->status);

        $state = MockPlatform::state();
        $this->assertCount(1, $state['charges']);

        $operation = ChargeOperation::firstOrFail();
        $this->assertSame(ChargeOperationStatus::Succeeded, $operation->status);
        $this->assertSame(1, $operation->attempts_count);

        $this->artisan('billing:run', ['--date' => '2026-11-20'])->assertSuccessful();
        $this->assertCount(1, MockPlatform::state()['charges']);
        $this->assertCount(1, MockPlatform::state()['debitRequests']);
    }

    public function test_stop_during_inflight_retryable_failure_suppresses_retry(): void
    {
        $subscriber = $this->subscriber();
        config(['services.platform.timeout_seconds' => 5.0]);
        MockPlatform::queueDebit('E1326', 1500);

        $process = $this->startBillingProcess();
        $this->waitForDebitRequest();
        $this->sendMo('STOP')->assertOk();
        $process->wait();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $operation = ChargeOperation::firstOrFail();
        $this->assertSame(ChargeOperationStatus::Cancelled, $operation->status);
        $this->assertSame(1, $operation->attempts_count);
        $this->assertSame(SubscriberStatus::Inactive, $subscriber->refresh()->status);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $this->artisan('billing:run', ['--date' => '2026-11-20'])->assertSuccessful();

        $this->assertCount(0, MockPlatform::state()['charges']);
        $this->assertCount(1, MockPlatform::state()['debitRequests']);
    }

    public function test_explicit_opt_in_after_stop_allows_new_charges(): void
    {
        $subscriber = $this->subscriber();
        $this->sendMo('STOP')->assertOk();
        $this->assertSame(SubscriberStatus::Inactive, $subscriber->refresh()->status);

        $this->postJson('/api/subscriptions', ['msisdn' => $subscriber->msisdn])
            ->assertOk()
            ->assertJsonPath('status', 'ACTIVE');

        $subscriber->refresh();
        $this->assertSame(SubscriberStatus::Active, $subscriber->status);
        $this->assertNull($subscriber->stopped_at);

        $this->artisan('billing:run', ['--date' => '2026-11-20'])->assertSuccessful();
        $this->assertCount(1, MockPlatform::state()['charges']);
    }

    private function subscriber(): Subscriber
    {
        return Subscriber::factory()->create([
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:1000000001',
        ]);
    }

    private function sendMo(string $message)
    {
        return $this->postJson('/api/webhooks/sms', [
            'applicationId' => 'APP_000123',
            'sourceAddress' => 'tel:1000000001',
            'message' => $message,
            'encoding' => '0',
            'version' => '1.0',
        ]);
    }

    private function startBillingProcess(): Process
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'billing:run', '--date=2026-11-20'],
            base_path(),
            MockPlatform::artisanEnvironment(),
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function waitForDebitRequest(): void
    {
        $deadline = microtime(true) + 5.0;

        do {
            usleep(100_000);
            $state = MockPlatform::state();
        } while ($state['debitRequestCount'] === 0 && microtime(true) < $deadline);

        $this->assertGreaterThan(0, $state['debitRequestCount'], 'Billing never reached the debit endpoint');
    }
}
