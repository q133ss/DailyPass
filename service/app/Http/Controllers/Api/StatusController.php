<?php

namespace App\Http\Controllers\Api;

use App\Models\ChargeOperation;
use App\Models\Subscriber;
use App\Support\BillingDay;
use App\Support\Msisdn;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StatusController
{
    public function show(Request $request): JsonResponse
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

        $subscriber = Subscriber::where('msisdn', $validated['msisdn'])->first();
        if ($subscriber === null) {
            return response()->json(['error' => 'subscriber_not_found'], 404);
        }

        $today = BillingDay::now();
        $operation = ChargeOperation::with('attempts')
            ->where('subscriber_id', $subscriber->id)
            ->whereDate('billing_day', $today->format('Y-m-d'))
            ->first();

        return response()->json([
            'subscriber' => $this->subscriberPayload($subscriber),
            'billingDay' => $today->format('Y-m-d'),
            'billingTimezone' => BillingDay::timezone(),
            'today' => $operation === null ? null : $this->operationPayload($operation),
        ]);
    }

    /** @return array<string, mixed> */
    private function subscriberPayload(Subscriber $subscriber): array
    {
        return [
            'msisdn' => $subscriber->msisdn,
            'maskedMsisdn' => Msisdn::mask($subscriber->msisdn),
            'subscriberId' => $subscriber->subscriber_id,
            'status' => $subscriber->status->value,
            'activatedAt' => $subscriber->activated_at?->toIso8601String(),
            'stoppedAt' => $subscriber->stopped_at?->toIso8601String(),
            'suspendedAt' => $subscriber->suspended_at?->toIso8601String(),
            'optOutStatus' => $subscriber->opt_out_status,
            'optOutConfirmedAt' => $subscriber->opt_out_confirmed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function operationPayload(ChargeOperation $operation): array
    {
        return [
            'billingDay' => $operation->billing_day->format('Y-m-d'),
            'externalTrxId' => $operation->external_trx_id,
            'amount' => $operation->amount,
            'currency' => $operation->currency,
            'status' => $operation->status->value,
            'attemptsCount' => $operation->attempts_count,
            'nextRetryAt' => $operation->next_retry_at?->toIso8601String(),
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
        ];
    }
}
