<?php

namespace App\Services;

use App\Carrier\CarrierPlatformClient;
use App\Carrier\CarrierResponse;
use App\Enums\SubscriberStatus;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Support\SubscriberAliasResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class OptInService
{
    public function __construct(
        private readonly CarrierPlatformClient $client,
        private readonly SubscriberAliasResolver $aliases,
        private readonly SmsService $sms,
    ) {}

    /**
     * @return array{
     *     subscriber: Subscriber,
     *     already_active: bool,
     *     carrier: CarrierResponse|null,
     *     welcome_sms: SmsMessage|null,
     *     stopped_during_opt_in: bool
     * }
     */
    public function optIn(string $msisdn): array
    {
        return Cache::lock('opt-in:'.$msisdn, 15)->block(10, function () use ($msisdn): array {
            $requestedAt = CarbonImmutable::now();

            $subscriber = DB::transaction(function () use ($msisdn, $requestedAt): Subscriber {
                $subscriber = Subscriber::where('msisdn', $msisdn)->lockForUpdate()->first();

                if ($subscriber?->isActive()) {
                    return $subscriber;
                }

                if ($subscriber === null) {
                    $subscriber = Subscriber::create([
                        'msisdn' => $msisdn,
                        'subscriber_id' => $this->aliases->resolve($msisdn),
                        'status' => SubscriberStatus::Inactive,
                        'opt_out_status' => 'NONE',
                    ]);
                }

                $subscriber->forceFill(['opt_in_requested_at' => $requestedAt])->save();

                return $subscriber;
            });

            if ($subscriber->isActive()) {
                return [
                    'subscriber' => $subscriber,
                    'already_active' => true,
                    'carrier' => null,
                    'welcome_sms' => null,
                    'stopped_during_opt_in' => false,
                ];
            }

            $response = $this->client->optIn($subscriber->subscriber_id);

            if (! $response->successful()) {
                return [
                    'subscriber' => $subscriber->refresh(),
                    'already_active' => false,
                    'carrier' => $response,
                    'welcome_sms' => null,
                    'stopped_during_opt_in' => false,
                ];
            }

            [$subscriber, $stoppedDuringOptIn] = DB::transaction(function () use ($subscriber, $requestedAt): array {
                $locked = Subscriber::whereKey($subscriber->id)->lockForUpdate()->firstOrFail();

                // STOP wins over an opt-in that was already in progress.
                if ($locked->stopped_at !== null && $locked->stopped_at->greaterThan($requestedAt)) {
                    $locked->forceFill([
                        'status' => SubscriberStatus::Inactive,
                        'opt_out_status' => 'UNCONFIRMED',
                    ])->save();

                    return [$locked, true];
                }

                $locked->forceFill([
                    'status' => SubscriberStatus::Active,
                    'activated_at' => $locked->activated_at ?? CarbonImmutable::now(),
                    'stopped_at' => null,
                    'suspended_at' => null,
                    'opt_out_status' => 'NONE',
                    'opt_out_confirmed_at' => null,
                ])->save();

                return [$locked, false];
            });

            if ($stoppedDuringOptIn) {
                // The carrier says REGISTERED, but the user stopped locally
                // after the request began. Undo the carrier side best-effort.
                $optOut = $this->client->optOut($subscriber->subscriber_id);
                $subscriber->forceFill([
                    'opt_out_status' => $optOut->successful() ? 'CONFIRMED' : 'UNCONFIRMED',
                    'opt_out_confirmed_at' => $optOut->successful() ? CarbonImmutable::now() : null,
                ])->save();

                return [
                    'subscriber' => $subscriber->refresh(),
                    'already_active' => false,
                    'carrier' => $response,
                    'welcome_sms' => null,
                    'stopped_during_opt_in' => true,
                ];
            }

            $welcome = $this->sms->send(
                $subscriber,
                'Welcome to Daily Pass! Your daily subscription is active. Reply STOP to unsubscribe.',
                'WELCOME',
            );

            return [
                'subscriber' => $subscriber->refresh(),
                'already_active' => false,
                'carrier' => $response,
                'welcome_sms' => $welcome,
                'stopped_during_opt_in' => false,
            ];
        });
    }
}
