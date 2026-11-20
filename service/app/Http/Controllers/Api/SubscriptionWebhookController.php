<?php

namespace App\Http\Controllers\Api;

use App\Services\SubscriptionWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SubscriptionWebhookController
{
    public function __construct(private readonly SubscriptionWebhookService $webhooks) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'applicationId' => ['required', 'string'],
            'subscriberId' => ['required', 'string', 'max:191'],
            'frequency' => ['required', 'string', 'max:32'],
            'status' => ['required', 'string', 'in:REGISTERED,UNREGISTERED,PENDING,CHARGE'],
            'version' => ['required', 'string', 'max:16'],
            'timeStamp' => ['required', 'string', 'max:64'],
        ]);

        if ($validated['applicationId'] !== (string) config('services.platform.application_id')) {
            return response()->json([
                'statusCode' => 'E1001',
                'statusDetail' => 'Invalid applicationId',
            ], 401);
        }

        $this->webhooks->handle($validated);

        return response()->json([
            'statusCode' => 'S1000',
            'statusDetail' => 'Success',
        ]);
    }
}
