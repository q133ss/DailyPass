<?php

namespace App\Http\Controllers\Api;

use App\Models\Subscriber;
use App\Services\OptInService;
use App\Support\Msisdn;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SubscriptionController
{
    public function __construct(private readonly OptInService $optIns) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'msisdn' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! Msisdn::isValid($value)) {
                        $fail('The msisdn must use the tel:<digits> format.');
                    }
                },
            ],
        ]);

        try {
            $result = $this->optIns->optIn($validated['msisdn']);
        } catch (Throwable $exception) {
            Log::error('opt_in.failed', ['exception' => $exception::class]);

            return response()->json([
                'error' => 'opt_in_failed',
                'message' => 'The subscription could not be activated.',
            ], 500);
        }

        /** @var Subscriber $subscriber */
        $subscriber = $result['subscriber'];

        if ($result['already_active']) {
            return response()->json([
                'status' => 'ACTIVE',
                'alreadyActive' => true,
                'msisdn' => $subscriber->msisdn,
                'maskedMsisdn' => Msisdn::mask($subscriber->msisdn),
                'subscriberId' => $subscriber->subscriber_id,
                'welcomeSms' => null,
            ]);
        }

        if ($result['stopped_during_opt_in']) {
            return response()->json([
                'status' => 'INACTIVE',
                'error' => 'stopped_during_opt_in',
                'message' => 'A STOP was accepted while the opt-in was in progress.',
                'subscriberId' => $subscriber->subscriber_id,
            ], 409);
        }

        if ($result['carrier'] !== null && ! $result['carrier']->successful()) {
            return response()->json([
                'status' => 'INACTIVE',
                'error' => 'carrier_rejected',
                'statusCode' => $result['carrier']->statusCode,
                'statusDetail' => $result['carrier']->statusDetail,
                'subscriberId' => $subscriber->subscriber_id,
            ], 502);
        }

        return response()->json([
            'status' => 'ACTIVE',
            'alreadyActive' => false,
            'msisdn' => $subscriber->msisdn,
            'maskedMsisdn' => Msisdn::mask($subscriber->msisdn),
            'subscriberId' => $subscriber->subscriber_id,
            'welcomeSms' => $result['welcome_sms'] === null ? null : [
                'status' => $result['welcome_sms']->status,
                'message' => $result['welcome_sms']->message,
            ],
        ]);
    }
}
