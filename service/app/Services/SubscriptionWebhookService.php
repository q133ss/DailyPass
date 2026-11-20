<?php

namespace App\Services;

use App\Enums\ChargeOperationStatus;
use App\Enums\SubscriberStatus;
use App\Models\ChargeOperation;
use App\Models\PlatformEvent;
use App\Models\Subscriber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SubscriptionWebhookService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $applicationId = (string) ($payload['applicationId'] ?? '');
        $subscriberId = (string) ($payload['subscriberId'] ?? '');
        $status = (string) ($payload['status'] ?? '');

        $eventTimestamp = $this->parseTimestamp($payload['timeStamp'] ?? null);
        $hash = hash('sha256', implode('|', [
            $applicationId,
            $subscriberId,
            (string) ($payload['frequency'] ?? ''),
            $status,
            (string) ($payload['version'] ?? ''),
            (string) ($payload['timeStamp'] ?? ''),
        ]));

        DB::transaction(function () use ($hash, $payload, $subscriberId, $status, $eventTimestamp): void {
            $event = PlatformEvent::createOrFirst(
                ['event_hash' => $hash],
                [
                    'subscriber_id' => $subscriberId,
                    'status' => $status,
                    'event_timestamp' => $eventTimestamp,
                    'payload' => $payload,
                ],
            );

            // createOrFirst returns the existing row for a duplicate delivery.
            if (! $event->wasRecentlyCreated) {
                return;
            }

            $subscriber = Subscriber::where('subscriber_id', $subscriberId)->lockForUpdate()->first();

            if ($subscriber === null) {
                Log::warning('webhook.unknown_subscriber', ['status' => $status]);
                $event->forceFill(['processed_at' => CarbonImmutable::now()])->save();

                return;
            }

            $eventAt = $eventTimestamp ?? CarbonImmutable::now();

            if ($status === 'REGISTERED') {
                $this->handleRegistered($subscriber, $eventAt);
            } elseif ($status === 'UNREGISTERED') {
                $this->handleUnregistered($subscriber);
            }

            $subscriber->forceFill(['last_webhook_at' => CarbonImmutable::now()])->save();
            $event->forceFill(['processed_at' => CarbonImmutable::now()])->save();
        });
    }

    private function handleRegistered(Subscriber $subscriber, CarbonImmutable $eventAt): void
    {
        if ($subscriber->isActive()) {
            return;
        }

        if ($subscriber->stopped_at !== null) {
            $intent = $subscriber->opt_in_requested_at;

            // A late or repeated REGISTERED must never resurrect a stopped
            // subscriber. Only an explicit opt-in started after the stop can.
            if ($intent === null
                || $intent->lessThanOrEqualTo($subscriber->stopped_at)
                || $eventAt->lessThan($intent)) {
                return;
            }
        }

        if ($subscriber->status === SubscriberStatus::Suspended) {
            $intent = $subscriber->opt_in_requested_at;

            if ($intent === null
                || $subscriber->suspended_at === null
                || $intent->lessThanOrEqualTo($subscriber->suspended_at)) {
                return;
            }
        }

        $subscriber->forceFill([
            'status' => SubscriberStatus::Active,
            'activated_at' => $subscriber->activated_at ?? CarbonImmutable::now(),
            'stopped_at' => null,
            'suspended_at' => null,
            'opt_out_status' => 'NONE',
            'opt_out_confirmed_at' => null,
        ])->save();
    }

    private function handleUnregistered(Subscriber $subscriber): void
    {
        $now = CarbonImmutable::now();

        $subscriber->forceFill([
            'status' => SubscriberStatus::Inactive,
            'stopped_at' => $now,
            'opt_out_status' => 'CONFIRMED',
            'opt_out_confirmed_at' => $now,
        ])->save();

        // Anything not yet sent to the carrier must not be sent after opt-out.
        ChargeOperation::where('subscriber_id', $subscriber->id)
            ->whereIn('status', [
                ChargeOperationStatus::Pending->value,
                ChargeOperationStatus::RetryScheduled->value,
            ])
            ->update([
                'status' => ChargeOperationStatus::Cancelled->value,
                'last_status_code' => 'STOPPED',
                'last_status_detail' => 'Cancelled by UNREGISTERED webhook',
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
