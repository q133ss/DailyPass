<?php

namespace App\Http\Controllers\Api;

use App\Models\ChargeOperation;
use App\Models\Subscriber;
use App\Support\Msisdn;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChargeHistoryController
{
    public function __invoke(Request $request): JsonResponse
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
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $subscriber = Subscriber::where('msisdn', $validated['msisdn'])->first();
        if ($subscriber === null) {
            return response()->json(['error' => 'subscriber_not_found'], 404);
        }

        $query = ChargeOperation::with('attempts')
            ->where('subscriber_id', $subscriber->id)
            ->orderByDesc('billing_day')
            ->limit(100);

        if (isset($validated['date'])) {
            $query->whereDate('billing_day', $validated['date']);
        }

        $charges = $query->get()->map(fn (ChargeOperation $operation): array => [
            'billingDay' => $operation->billing_day->format('Y-m-d'),
            'externalTrxId' => $operation->external_trx_id,
            'amount' => $operation->amount,
            'currency' => $operation->currency,
            'status' => $operation->status->value,
            'attemptsCount' => $operation->attempts_count,
            'referenceId' => $operation->reference_id,
            'internalTrxId' => $operation->internal_trx_id,
            'lastStatusCode' => $operation->last_status_code,
            'lastStatusDetail' => $operation->last_status_detail,
            'receiptSentAt' => $operation->receipt_sent_at?->toIso8601String(),
            'attempts' => $operation->attempts->map(static fn ($attempt): array => [
                'attemptNo' => $attempt->attempt_no,
                'status' => $attempt->status->value,
                'statusCode' => $attempt->status_code,
                'statusDetail' => $attempt->status_detail,
                'referenceId' => $attempt->reference_id,
                'startedAt' => $attempt->started_at->toIso8601String(),
                'finishedAt' => $attempt->finished_at?->toIso8601String(),
            ])->all(),
        ]);

        return response()->json([
            'subscriber' => [
                'msisdn' => $subscriber->msisdn,
                'maskedMsisdn' => Msisdn::mask($subscriber->msisdn),
                'subscriberId' => $subscriber->subscriber_id,
                'status' => $subscriber->status->value,
            ],
            'charges' => $charges,
        ]);
    }
}
