<?php

namespace Tests\Feature;

use App\Enums\ChargeAttemptStatus;
use App\Enums\ChargeOperationStatus;
use App\Enums\SubscriberStatus;
use App\Models\ChargeAttempt;
use App\Models\ChargeOperation;
use App\Models\Subscriber;
use Tests\TestCase;

class StatusTest extends TestCase
{
    public function test_status_returns_subscriber_and_todays_charge_with_reference(): void
    {
        $subscriber = Subscriber::factory()->create([
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:1000000001',
        ]);

        $operation = ChargeOperation::create([
            'subscriber_id' => $subscriber->id,
            'billing_day' => '2026-11-20',
            'external_trx_id' => 'DP-20261120-'.$subscriber->id,
            'amount' => '5.00',
            'currency' => 'XTS',
            'status' => ChargeOperationStatus::Succeeded,
            'attempts_count' => 1,
            'reference_id' => '80430074',
            'internal_trx_id' => '913080108430074',
            'last_status_code' => 'S1000',
            'receipt_sent_at' => now(),
        ]);

        ChargeAttempt::create([
            'charge_operation_id' => $operation->id,
            'attempt_no' => 1,
            'external_trx_id' => $operation->external_trx_id,
            'status' => ChargeAttemptStatus::Succeeded,
            'status_code' => 'S1000',
            'status_detail' => 'Request was successfully processed',
            'reference_id' => '80430074',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->getJson('/api/status?msisdn=tel%3A1000000001')
            ->assertOk()
            ->assertJsonPath('subscriber.status', SubscriberStatus::Active->value)
            ->assertJsonPath('subscriber.maskedMsisdn', 'tel:******0001')
            ->assertJsonPath('billingDay', '2026-11-20')
            ->assertJsonPath('today.referenceId', '80430074')
            ->assertJsonPath('today.status', ChargeOperationStatus::Succeeded->value)
            ->assertJsonPath('today.attemptsCount', 1)
            ->assertJsonPath('today.attempts.0.statusCode', 'S1000');
    }

    public function test_charge_history_answers_was_msisdn_charged(): void
    {
        $subscriber = Subscriber::factory()->create([
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:masked-alias',
        ]);

        ChargeOperation::create([
            'subscriber_id' => $subscriber->id,
            'billing_day' => '2026-11-19',
            'external_trx_id' => 'DP-20261119-'.$subscriber->id,
            'amount' => '5.00',
            'currency' => 'XTS',
            'status' => ChargeOperationStatus::FailedExhausted,
            'attempts_count' => 3,
            'last_status_code' => 'E1326',
        ]);

        $this->getJson('/api/charges?msisdn=tel%3A1000000001&date=2026-11-19')
            ->assertOk()
            ->assertJsonCount(1, 'charges')
            ->assertJsonPath('charges.0.externalTrxId', 'DP-20261119-'.$subscriber->id)
            ->assertJsonPath('charges.0.lastStatusCode', 'E1326')
            ->assertJsonPath('subscriber.subscriberId', 'tel:masked-alias');
    }

    public function test_unknown_subscriber_returns_404(): void
    {
        $this->getJson('/api/status?msisdn=tel%3A1999999999')
            ->assertNotFound()
            ->assertJsonPath('error', 'subscriber_not_found');
    }

    public function test_invalid_status_query_is_rejected(): void
    {
        $this->getJson('/api/status?msisdn=1000000001')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['msisdn']);
    }
}
