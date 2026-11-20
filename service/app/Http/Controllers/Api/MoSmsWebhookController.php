<?php

namespace App\Http\Controllers\Api;

use App\Models\Subscriber;
use App\Services\StopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class MoSmsWebhookController
{
    public function __construct(private readonly StopService $stops) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'applicationId' => ['required', 'string'],
            'sourceAddress' => ['required', 'string', 'max:191'],
            'message' => ['required', 'string', 'max:1600'],
            'encoding' => ['required', 'string', 'max:8'],
            'version' => ['required', 'string', 'max:16'],
        ]);

        if ($validated['applicationId'] !== (string) config('services.platform.application_id')) {
            return response()->json([
                'statusCode' => 'E1001',
                'statusDetail' => 'Invalid applicationId',
            ], 401);
        }

        $subscriber = Subscriber::query()
            ->where('msisdn', $validated['sourceAddress'])
            ->orWhere('subscriber_id', $validated['sourceAddress'])
            ->first();

        if ($subscriber === null) {
            Log::warning('mo.unknown_subscriber');

            return $this->ack();
        }

        $this->stops->handleInbound($subscriber, $validated['message']);

        return $this->ack();
    }

    private function ack(): JsonResponse
    {
        return response()->json([
            'statusCode' => 'S1000',
            'statusDetail' => 'Success',
        ]);
    }
}
