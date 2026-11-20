<?php

namespace App\Services;

use App\Carrier\CarrierPlatformClient;
use App\Enums\ChargeOperationStatus;
use App\Enums\SubscriberStatus;
use App\Models\ChargeOperation;
use App\Models\Subscriber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class StopService
{
    public const GOODBYE = 'You have unsubscribed from Daily Pass. You will not be charged again.';

    public function __construct(
        private readonly CarrierPlatformClient $client,
        private readonly SmsService $sms,
    ) {}

    /**
     * Accept an inbound MO message. STOP state is committed before the carrier
     * call, so billing starts refusing new requests immediately.
     *
     * @return array<string, mixed>
     */
    public function handleInbound(Subscriber $subscriber, string $message): array
    {
        $isStop = preg_match('/^\s*STOP\s*$/i', $message) === 1;

        if (! $isStop) {
            $this->sms->recordInbound($subscriber, $message);

            return ['stop' => false, 'first_stop' => false, 'opt_out_confirmed' => null, 'goodbye_sms' => null];
        }

        [$subscriber, $firstStop] = DB::transaction(function () use ($subscriber, $message): array {
            $locked = Subscriber::whereKey($subscriber->id)->lockForUpdate()->firstOrFail();

            $this->sms->recordInbound($locked, $message);

            if ($locked->status === SubscriberStatus::Inactive && $locked->stopped_at !== null) {
                return [$locked, false];
            }

            $now = CarbonImmutable::now();

            $locked->forceFill([
                'status' => SubscriberStatus::Inactive,
                'stopped_at' => $now,
                'opt_out_status' => 'PENDING',
                'opt_out_attempts' => $locked->opt_out_attempts + 1,
                'opt_out_confirmed_at' => null,
            ])->save();

            // A request that has not reached the carrier yet must never be
            // sent after STOP. A request already in PROCESSING is in flight and
            // is handled honestly when its response arrives.
            ChargeOperation::where('subscriber_id', $locked->id)
                ->whereIn('status', [
                    ChargeOperationStatus::Pending->value,
                    ChargeOperationStatus::RetryScheduled->value,
                ])
                ->update([
                    'status' => ChargeOperationStatus::Cancelled->value,
                    'last_status_code' => 'STOPPED',
                    'last_status_detail' => 'Cancelled because STOP was accepted',
                    'next_retry_at' => null,
                    'resolved_at' => $now,
                    'updated_at' => $now,
                ]);

            return [$locked, true];
        });

        if (! $firstStop) {
            return [
                'stop' => true,
                'first_stop' => false,
                'opt_out_confirmed' => $subscriber->opt_out_status === 'CONFIRMED',
                'goodbye_sms' => null,
            ];
        }

        $response = $this->client->optOut($subscriber->subscriber_id);
        $confirmed = $response->successful();
        $now = CarbonImmutable::now();

        $subscriber->forceFill([
            'opt_out_status' => $confirmed ? 'CONFIRMED' : 'UNCONFIRMED',
            'opt_out_confirmed_at' => $confirmed ? $now : null,
        ])->save();

        if (! $confirmed) {
            Log::warning('stop.opt_out_unconfirmed', [
                'statusCode' => $response->statusCode,
                'transportError' => $response->transportError,
            ]);
        }

        // Local state is already safe even when the carrier call failed. The
        // goodbye is still useful to the user and is sent once.
        $goodbye = $this->sms->send($subscriber, self::GOODBYE, 'GOODBYE');

        return [
            'stop' => true,
            'first_stop' => true,
            'opt_out_confirmed' => $confirmed,
            'goodbye_sms' => $goodbye,
        ];
    }

    /**
     * Reconciliation for opt-outs that timed out at the carrier. Local billing
     * is already blocked; this only settles the carrier-side state.
     *
     * @return array{checked:int,confirmed:int,stillUnconfirmed:int}
     */
    public function retryUnconfirmed(): array
    {
        $maxAttempts = max(1, (int) config('billing.opt_out_max_attempts', 5));
        $subscribers = Subscriber::query()
            ->where('status', SubscriberStatus::Inactive)
            ->whereIn('opt_out_status', ['PENDING', 'UNCONFIRMED'])
            ->where('opt_out_attempts', '<', $maxAttempts)
            ->orderBy('id')
            ->get();

        $confirmed = 0;
        $stillUnconfirmed = 0;

        foreach ($subscribers as $subscriber) {
            $subscriber->forceFill(['opt_out_attempts' => $subscriber->opt_out_attempts + 1])->save();
            $response = $this->client->optOut($subscriber->subscriber_id);

            if ($response->successful()) {
                $subscriber->forceFill([
                    'opt_out_status' => 'CONFIRMED',
                    'opt_out_confirmed_at' => CarbonImmutable::now(),
                ])->save();
                $confirmed++;
            } else {
                $subscriber->forceFill(['opt_out_status' => 'UNCONFIRMED'])->save();
                $stillUnconfirmed++;
            }
        }

        return [
            'checked' => $subscribers->count(),
            'confirmed' => $confirmed,
            'stillUnconfirmed' => $stillUnconfirmed,
        ];
    }
}
