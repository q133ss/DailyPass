<?php

namespace App\Services;

use App\Carrier\CarrierPlatformClient;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use Throwable;

final class SmsService
{
    public function __construct(private readonly CarrierPlatformClient $client) {}

    public function send(Subscriber $subscriber, string $message, string $kind): SmsMessage
    {
        $sms = SmsMessage::create([
            'subscriber_id' => $subscriber->id,
            'direction' => 'MT',
            'kind' => $kind,
            'address' => $subscriber->msisdn,
            'message' => $message,
            'status' => 'PENDING',
        ]);

        try {
            $response = $this->client->sendSms($subscriber->msisdn, $message);
        } catch (Throwable $exception) {
            $sms->update([
                'status' => 'FAILED',
                'response_payload' => ['error' => $exception::class],
            ]);

            return $sms;
        }

        $sms->update([
            'status' => $response->successful() ? 'SENT' : 'FAILED',
            'status_code' => $response->statusCode,
            'response_payload' => $response->toAuditArray(),
            'sent_at' => $response->successful() ? now() : null,
        ]);

        return $sms;
    }

    public function recordInbound(Subscriber $subscriber, string $message): SmsMessage
    {
        return SmsMessage::create([
            'subscriber_id' => $subscriber->id,
            'direction' => 'MO',
            'kind' => 'INBOUND',
            'address' => $subscriber->msisdn,
            'message' => $message,
            'status' => 'RECEIVED',
            'sent_at' => now(),
        ]);
    }
}
