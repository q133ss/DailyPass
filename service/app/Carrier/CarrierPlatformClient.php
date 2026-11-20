<?php

namespace App\Carrier;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class CarrierPlatformClient
{
    /**
     * The request body contains the password, so it is never logged and never
     * included in exception messages. Only operation names and status codes
     * are written to the application log.
     */
    public function optIn(string $subscriberId): CarrierResponse
    {
        return $this->subscription('1', $subscriberId);
    }

    public function optOut(string $subscriberId): CarrierResponse
    {
        return $this->subscription('0', $subscriberId);
    }

    public function debit(string $externalTrxId, string $subscriberId, string $amount, string $currency): CarrierResponse
    {
        if (preg_match('/^\d+\.\d{2}$/', $amount) !== 1) {
            throw new RuntimeException('Refusing to send an amount without exactly two decimal places');
        }

        return $this->post('/caas/direct/debit', [
            'externalTrxId' => $externalTrxId,
            'subscriberId' => $subscriberId,
            'paymentInstrumentName' => 'Mobile Account',
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    public function sendSms(string $destination, string $message): CarrierResponse
    {
        return $this->post('/sms/send', [
            'message' => $message,
            'destinationAddresses' => [$destination],
            'version' => '1.0',
            'sourceAddress' => (string) config('services.platform.sms_source', 'DailyPass'),
            'encoding' => '0',
        ]);
    }

    private function subscription(string $action, string $subscriberId): CarrierResponse
    {
        if ($subscriberId === '') {
            throw new RuntimeException('subscriberId is required');
        }

        return $this->post('/subscription/send', [
            'version' => '1.0',
            'action' => $action,
            'subscriberId' => $subscriberId,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): CarrierResponse
    {
        $this->assertConfigured();
        $operation = match ($path) {
            '/subscription/send' => ($body['action'] ?? '') === '1' ? 'opt-in' : 'opt-out',
            '/caas/direct/debit' => 'debit',
            '/sms/send' => 'sms',
            default => 'request',
        };

        Log::info('carrier.request', [
            'operation' => $operation,
            'externalTrxId' => $body['externalTrxId'] ?? null,
        ]);

        $url = rtrim((string) config('services.platform.base_url'), '/').$path;
        $timeout = (float) config('services.platform.timeout_seconds', 3);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(min(2.0, $timeout))
                ->acceptJson()
                ->asJson()
                ->post($url, array_merge($this->credentials(), $body));

            $payload = $response->json();
            if (! is_array($payload)) {
                Log::warning('carrier.invalid_response', [
                    'operation' => $operation,
                    'httpStatus' => $response->status(),
                ]);

                return new CarrierResponse(null, null, [], false, false, 'Response was not a JSON object');
            }

            Log::info('carrier.response', [
                'operation' => $operation,
                'externalTrxId' => $body['externalTrxId'] ?? null,
                'httpStatus' => $response->status(),
                'statusCode' => $payload['statusCode'] ?? null,
            ]);

            return new CarrierResponse(
                isset($payload['statusCode']) ? (string) $payload['statusCode'] : null,
                isset($payload['statusDetail']) ? (string) $payload['statusDetail'] : null,
                $payload,
            );
        } catch (ConnectionException $exception) {
            $timedOut = $this->looksLikeTimeout($exception);

            Log::warning('carrier.transport_error', [
                'operation' => $operation,
                'externalTrxId' => $body['externalTrxId'] ?? null,
                'timedOut' => $timedOut,
                'exception' => $exception::class,
            ]);

            return new CarrierResponse(
                null,
                null,
                [],
                true,
                $timedOut,
                'Transport error ('.$exception::class.')',
            );
        } catch (Throwable $exception) {
            Log::error('carrier.unexpected_error', [
                'operation' => $operation,
                'externalTrxId' => $body['externalTrxId'] ?? null,
                'exception' => $exception::class,
            ]);

            return new CarrierResponse(null, null, [], true, false, 'Unexpected transport error');
        }
    }

    private function assertConfigured(): void
    {
        if ((string) config('services.platform.base_url') === ''
            || (string) config('services.platform.application_id') === ''
            || (string) config('services.platform.password') === '') {
            throw new RuntimeException('Carrier credentials are not configured');
        }
    }

    /** @return array{applicationId:string,password:string} */
    private function credentials(): array
    {
        return [
            'applicationId' => (string) config('services.platform.application_id'),
            'password' => (string) config('services.platform.password'),
        ];
    }

    private function looksLikeTimeout(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28');
    }
}
