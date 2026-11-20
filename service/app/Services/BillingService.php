<?php

namespace App\Services;

use App\Carrier\CarrierPlatformClient;
use App\Carrier\CarrierResponse;
use App\Enums\ChargeAttemptStatus;
use App\Enums\ChargeOperationStatus;
use App\Enums\SubscriberStatus;
use App\Models\ChargeAttempt;
use App\Models\ChargeOperation;
use App\Models\Subscriber;
use App\Support\BillingDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class BillingService
{
    public function __construct(
        private readonly CarrierPlatformClient $client,
        private readonly SmsService $sms,
    ) {}

    /**
     * Charge all eligible subscribers for one billing day and process due retries.
     *
     * @return array<string, mixed>
     */
    public function run(CarbonImmutable $day, ?Subscriber $only = null): array
    {
        $amount = $this->validatedAmount();
        $currency = (string) config('billing.currency', 'XTS');
        $day = $day->startOfDay();

        $stats = [
            'billingDay' => $day->format('Y-m-d'),
            'timezone' => BillingDay::timezone(),
            'amount' => $amount,
            'currency' => $currency,
            'created' => 0,
            'succeeded' => 0,
            'retryScheduled' => 0,
            'failed' => 0,
            'unknown' => 0,
            'cancelled' => 0,
            'skipped' => 0,
            'expiredOld' => 0,
            'expiredAtBoundary' => 0,
        ];

        $stats['expiredOld'] = $this->expirePreviousDays($day);
        $this->recoverStaleClaims($day);

        $subscribers = Subscriber::query()
            ->where('status', SubscriberStatus::Active)
            ->whereNull('stopped_at')
            ->when($only !== null, fn ($query) => $query->whereKey($only->id))
            ->orderBy('id')
            ->get();

        foreach ($subscribers as $subscriber) {
            $operation = $this->ensureOperation($subscriber, $day, $amount, $currency);
            if ($operation->wasRecentlyCreated) {
                $stats['created']++;
            }
        }

        $operations = ChargeOperation::query()
            ->where('billing_day', $day->format('Y-m-d'))
            ->whereIn('status', [
                ChargeOperationStatus::Pending->value,
                ChargeOperationStatus::RetryScheduled->value,
            ])
            ->when($only !== null, fn ($query) => $query->where('subscriber_id', $only->id))
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', CarbonImmutable::now());
            })
            ->orderBy('id')
            ->get();

        foreach ($operations as $operation) {
            $outcome = $this->processOperation($operation, $day);
            $stats[match ($outcome) {
                'succeeded' => 'succeeded',
                'retry_scheduled' => 'retryScheduled',
                'failed' => 'failed',
                'unknown' => 'unknown',
                'cancelled' => 'cancelled',
                'expired' => 'expiredAtBoundary',
                default => 'skipped',
            }]++;
        }

        return $stats;
    }

    public function externalTrxIdFor(Subscriber $subscriber, CarbonImmutable $day): string
    {
        return sprintf('DP-%s-%d', $day->format('Ymd'), $subscriber->id);
    }

    private function ensureOperation(
        Subscriber $subscriber,
        CarbonImmutable $day,
        string $amount,
        string $currency,
    ): ChargeOperation {
        return ChargeOperation::createOrFirst(
            [
                'subscriber_id' => $subscriber->id,
                'billing_day' => $day->format('Y-m-d'),
            ],
            [
                'external_trx_id' => $this->externalTrxIdFor($subscriber, $day),
                'amount' => $amount,
                'currency' => $currency,
                'status' => ChargeOperationStatus::Pending,
            ],
        );
    }

    /**
     * Claim an operation and persist the attempt row before any HTTP call.
     * The row lock makes concurrent billing processes cooperate; the HTTP
     * request itself is made outside the transaction.
     */
    private function processOperation(ChargeOperation $operation, CarbonImmutable $day): string
    {
        $claim = DB::transaction(function () use ($operation, $day): ?array {
            $locked = ChargeOperation::whereKey($operation->id)->lockForUpdate()->first();

            if ($locked === null || $locked->isTerminal()) {
                return null;
            }

            if ($locked->billing_day->format('Y-m-d') !== $day->format('Y-m-d')) {
                return null;
            }

            if ($locked->status === ChargeOperationStatus::Processing) {
                return null;
            }

            if ($locked->next_retry_at !== null && $locked->next_retry_at->isFuture()) {
                return null;
            }

            $subscriber = Subscriber::whereKey($locked->subscriber_id)->lockForUpdate()->first();

            // STOP/inactive is checked immediately before a new request starts.
            if ($subscriber === null || $subscriber->stopped_at !== null || ! $subscriber->isActive()) {
                $locked->forceFill([
                    'status' => ChargeOperationStatus::Cancelled,
                    'last_status_code' => 'STOPPED_OR_INACTIVE',
                    'last_status_detail' => 'No charge started because the subscriber is not active',
                    'next_retry_at' => null,
                    'resolved_at' => CarbonImmutable::now(),
                ])->save();

                return null;
            }

            $maxAttempts = max(1, (int) config('billing.max_attempts', 3));

            if ($locked->attempts_count >= $maxAttempts) {
                $locked->forceFill([
                    'status' => ChargeOperationStatus::FailedExhausted,
                    'last_status_detail' => 'Retry budget exhausted before the next attempt',
                    'next_retry_at' => null,
                    'resolved_at' => CarbonImmutable::now(),
                ])->save();

                return null;
            }

            $attemptNo = $locked->attempts_count + 1;
            $attempt = ChargeAttempt::create([
                'charge_operation_id' => $locked->id,
                'attempt_no' => $attemptNo,
                'external_trx_id' => $locked->external_trx_id,
                'status' => ChargeAttemptStatus::Pending,
                'started_at' => CarbonImmutable::now(),
            ]);

            $locked->forceFill([
                'status' => ChargeOperationStatus::Processing,
                'attempts_count' => $attemptNo,
                'next_retry_at' => null,
                'last_status_code' => null,
                'last_status_detail' => null,
            ])->save();

            return [
                'operation_id' => $locked->id,
                'attempt_id' => $attempt->id,
                'attempt_no' => $attemptNo,
                'external_trx_id' => $locked->external_trx_id,
                'subscriber_id' => $subscriber->subscriber_id,
                'amount' => $locked->amount,
                'currency' => $locked->currency,
            ];
        });

        if ($claim === null) {
            return 'skipped';
        }

        $response = $this->client->debit(
            $claim['external_trx_id'],
            $claim['subscriber_id'],
            (string) $claim['amount'],
            $claim['currency'],
        );

        $outcome = $this->recordResponse($claim, $response);

        if ($outcome === 'succeeded') {
            $this->sendReceipt($claim['operation_id']);
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function recordResponse(array $claim, CarrierResponse $response): string
    {
        return DB::transaction(function () use ($claim, $response): string {
            $operation = ChargeOperation::whereKey($claim['operation_id'])->lockForUpdate()->firstOrFail();
            $attempt = ChargeAttempt::whereKey($claim['attempt_id'])->lockForUpdate()->firstOrFail();
            $subscriber = Subscriber::whereKey($operation->subscriber_id)->lockForUpdate()->first();
            $now = CarbonImmutable::now();

            $attempt->forceFill([
                'status_code' => $response->statusCode,
                'status_detail' => $response->statusDetail,
                'reference_id' => $response->field('referenceId'),
                'internal_trx_id' => $response->field('internalTrxId'),
                'response_payload' => $response->toAuditArray(),
                'error' => $response->error,
                'finished_at' => $now,
            ]);

            if ($response->transportError) {
                return $this->recordTransportError($operation, $attempt, $subscriber, $response, $claim, $now);
            }

            return match ($response->statusCode) {
                'S1000' => $this->recordSuccess($operation, $attempt, $response, $now),
                'E1326', 'E1318' => $this->recordRetryableFailure($operation, $attempt, $subscriber, $response, $claim, $now),
                'E1308' => $this->recordPermanentFailure($operation, $attempt, $subscriber, $response, $now),
                'E1337' => $this->recordDuplicate($operation, $attempt, $response, $now),
                default => $this->recordUnknown($operation, $attempt, $response, $now, 'Unrecognised carrier status code'),
            };
        });
    }

    private function recordSuccess(
        ChargeOperation $operation,
        ChargeAttempt $attempt,
        CarrierResponse $response,
        CarbonImmutable $now,
    ): string {
        $referenceId = $response->field('referenceId');

        $attempt->forceFill([
            'status' => ChargeAttemptStatus::Succeeded,
            'finished_at' => $now,
        ])->save();

        $operation->forceFill([
            'status' => ChargeOperationStatus::Succeeded,
            'reference_id' => $referenceId,
            'internal_trx_id' => $response->field('internalTrxId'),
            'last_status_code' => 'S1000',
            'last_status_detail' => $response->statusDetail,
            'next_retry_at' => null,
            'resolved_at' => $now,
        ])->save();

        return 'succeeded';
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function recordRetryableFailure(
        ChargeOperation $operation,
        ChargeAttempt $attempt,
        ?Subscriber $subscriber,
        CarrierResponse $response,
        array $claim,
        CarbonImmutable $now,
    ): string {
        $attempt->forceFill([
            'status' => ChargeAttemptStatus::FailedRetryable,
            'finished_at' => $now,
        ])->save();

        $operation->forceFill([
            'last_status_code' => $response->statusCode,
            'last_status_detail' => $response->statusDetail,
        ]);

        // A STOP that arrived while the request was in flight wins: no retry
        // is scheduled for a subscriber who is no longer active.
        if ($subscriber === null || $subscriber->stopped_at !== null || ! $subscriber->isActive()) {
            $operation->forceFill([
                'status' => ChargeOperationStatus::Cancelled,
                'last_status_detail' => 'Retry suppressed because the subscriber is not active',
                'resolved_at' => $now,
                'next_retry_at' => null,
            ])->save();

            return 'cancelled';
        }

        $maxAttempts = max(1, (int) config('billing.max_attempts', 3));

        if ($claim['attempt_no'] >= $maxAttempts) {
            $operation->forceFill([
                'status' => ChargeOperationStatus::FailedExhausted,
                'resolved_at' => $now,
                'next_retry_at' => null,
            ])->save();

            return 'failed';
        }

        $next = $this->nextRetryAt($claim['attempt_no'], $now);

        if ($next->greaterThan($this->endOfBillingDay($operation))) {
            $operation->forceFill([
                'status' => ChargeOperationStatus::Expired,
                'last_status_detail' => 'Retry window closed at the billing day boundary',
                'resolved_at' => $now,
                'next_retry_at' => null,
            ])->save();

            return 'expired';
        }

        $operation->forceFill([
            'status' => ChargeOperationStatus::RetryScheduled,
            'next_retry_at' => $next,
            'resolved_at' => null,
        ])->save();

        return 'retry_scheduled';
    }

    private function recordPermanentFailure(
        ChargeOperation $operation,
        ChargeAttempt $attempt,
        ?Subscriber $subscriber,
        CarrierResponse $response,
        CarbonImmutable $now,
    ): string {
        $attempt->forceFill([
            'status' => ChargeAttemptStatus::FailedPermanent,
            'finished_at' => $now,
        ])->save();

        $operation->forceFill([
            'status' => ChargeOperationStatus::FailedPermanent,
            'last_status_code' => 'E1308',
            'last_status_detail' => $response->statusDetail,
            'next_retry_at' => null,
            'resolved_at' => $now,
        ])->save();

        if ($subscriber !== null && $subscriber->isActive()) {
            $subscriber->forceFill([
                'status' => SubscriberStatus::Suspended,
                'suspended_at' => $now,
            ])->save();
        }

        return 'failed';
    }

    private function recordDuplicate(
        ChargeOperation $operation,
        ChargeAttempt $attempt,
        CarrierResponse $response,
        CarbonImmutable $now,
    ): string {
        $referenceId = $response->field('referenceId');

        // E1337 can resolve a lost response, but only if the carrier returned
        // the original transaction data. Otherwise the outcome is unknown and
        // no receipt or invented reference is allowed.
        if ($referenceId === null) {
            $attempt->forceFill([
                'status' => ChargeAttemptStatus::Unknown,
                'finished_at' => $now,
            ])->save();

            return $this->recordUnknown(
                $operation,
                $attempt,
                $response,
                $now,
                'Duplicate acknowledgement without original transaction data',
            );
        }

        $attempt->forceFill([
            'status' => ChargeAttemptStatus::DuplicateConfirmed,
            'reference_id' => $referenceId,
            'finished_at' => $now,
        ])->save();

        $operation->forceFill([
            'status' => ChargeOperationStatus::Succeeded,
            'reference_id' => $referenceId,
            'internal_trx_id' => $response->field('internalTrxId'),
            'last_status_code' => 'E1337',
            'last_status_detail' => 'Duplicate confirmed with original transaction data',
            'next_retry_at' => null,
            'resolved_at' => $now,
        ])->save();

        return 'succeeded';
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function recordTransportError(
        ChargeOperation $operation,
        ChargeAttempt $attempt,
        ?Subscriber $subscriber,
        CarrierResponse $response,
        array $claim,
        CarbonImmutable $now,
    ): string {
        $attempt->forceFill([
            'status' => $response->timedOut ? ChargeAttemptStatus::Timeout : ChargeAttemptStatus::Unknown,
            'finished_at' => $now,
        ])->save();

        $operation->forceFill([
            'last_status_code' => null,
            'last_status_detail' => $response->error,
        ]);

        // Once STOP is accepted an ambiguous request must not be retried or
        // turned into a success. Money may have moved; a human must review it.
        if ($subscriber === null || $subscriber->stopped_at !== null || ! $subscriber->isActive()) {
            $operation->forceFill([
                'status' => ChargeOperationStatus::Unknown,
                'last_status_detail' => 'Transport error after STOP; outcome has to be reviewed',
                'resolved_at' => $now,
                'next_retry_at' => null,
            ])->save();

            return 'unknown';
        }

        $maxAttempts = max(1, (int) config('billing.max_attempts', 3));

        if ($claim['attempt_no'] >= $maxAttempts) {
            $operation->forceFill([
                'status' => ChargeOperationStatus::Unknown,
                'last_status_detail' => 'Transport error and retry budget exhausted',
                'resolved_at' => $now,
                'next_retry_at' => null,
            ])->save();

            return 'unknown';
        }

        $next = $this->nextRetryAt($claim['attempt_no'], $now);

        if ($next->greaterThan($this->endOfBillingDay($operation))) {
            $operation->forceFill([
                'status' => ChargeOperationStatus::Unknown,
                'last_status_detail' => 'Transport error at the billing day boundary',
                'resolved_at' => $now,
                'next_retry_at' => null,
            ])->save();

            return 'unknown';
        }

        $operation->forceFill([
            'status' => ChargeOperationStatus::RetryScheduled,
            'next_retry_at' => $next,
            'resolved_at' => null,
        ])->save();

        return 'retry_scheduled';
    }

    private function recordUnknown(
        ChargeOperation $operation,
        ChargeAttempt $attempt,
        CarrierResponse $response,
        CarbonImmutable $now,
        string $detail,
    ): string {
        $attempt->forceFill([
            'status' => ChargeAttemptStatus::Unknown,
            'finished_at' => $now,
        ])->save();

        $operation->forceFill([
            'status' => ChargeOperationStatus::Unknown,
            'last_status_code' => $response->statusCode,
            'last_status_detail' => $detail,
            'next_retry_at' => null,
            'resolved_at' => $now,
        ])->save();

        return 'unknown';
    }

    private function sendReceipt(int $operationId): void
    {
        $operation = ChargeOperation::with('subscriber')->find($operationId);

        if ($operation === null
            || $operation->status !== ChargeOperationStatus::Succeeded
            || $operation->receipt_sent_at !== null
            || $operation->reference_id === null) {
            return;
        }

        $subscriber = $operation->subscriber;

        if ($subscriber === null) {
            return;
        }

        $message = sprintf(
            'Daily Pass: charged %s %s for %s. Ref: %s.',
            $operation->amount,
            $operation->currency,
            $operation->billing_day->format('Y-m-d'),
            $operation->reference_id,
        );

        $sms = $this->sms->send($subscriber, $message, 'RECEIPT');

        // Mark the receipt as attempted so a repeated command cannot send it
        // twice. The SMS row keeps the delivery status for audit.
        $operation->forceFill(['receipt_sent_at' => CarbonImmutable::now()])->save();

        if ($sms->status !== 'SENT') {
            Log::warning('billing.receipt_failed', ['operationId' => $operation->id]);
        }
    }

    private function nextRetryAt(int $attemptNo, CarbonImmutable $now): CarbonImmutable
    {
        $delays = config('billing.retry_delays_seconds', [60, 300]);
        $delays = is_array($delays) && $delays !== [] ? array_values($delays) : [60];
        $seconds = (int) $delays[min(max(0, $attemptNo - 1), count($delays) - 1)];

        return $now->addSeconds(max(0, $seconds));
    }

    private function endOfBillingDay(ChargeOperation $operation): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $operation->billing_day->format('Y-m-d'),
            BillingDay::timezone(),
        )->endOfDay();
    }

    private function expirePreviousDays(CarbonImmutable $day): int
    {
        return ChargeOperation::query()
            ->where('billing_day', '<', $day->format('Y-m-d'))
            ->whereIn('status', [
                ChargeOperationStatus::Pending->value,
                ChargeOperationStatus::Processing->value,
                ChargeOperationStatus::RetryScheduled->value,
            ])
            ->update([
                'status' => ChargeOperationStatus::Expired->value,
                'last_status_code' => 'DAY_BOUNDARY',
                'last_status_detail' => 'Retry window closed at the billing day boundary',
                'next_retry_at' => null,
                'resolved_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);
    }

    private function recoverStaleClaims(CarbonImmutable $day): void
    {
        ChargeOperation::query()
            ->where('billing_day', $day->format('Y-m-d'))
            ->where('status', ChargeOperationStatus::Processing->value)
            ->where('updated_at', '<', CarbonImmutable::now()->subMinutes(10))
            ->update([
                'status' => ChargeOperationStatus::RetryScheduled->value,
                'next_retry_at' => CarbonImmutable::now(),
                'last_status_detail' => 'Recovered after an interrupted billing process; the same externalTrxId will be reused',
                'updated_at' => CarbonImmutable::now(),
            ]);
    }

    private function validatedAmount(): string
    {
        $amount = (string) config('billing.daily_amount', '5.00');

        if (preg_match('/^\d{1,10}\.\d{2}$/', $amount) !== 1) {
            throw new RuntimeException('BILLING_DAILY_AMOUNT must have exactly two decimal places');
        }

        return $amount;
    }
}
