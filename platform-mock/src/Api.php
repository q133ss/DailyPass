<?php

declare(strict_types=1);

require_once __DIR__ . '/State.php';

/**
 * Carrier Platform mock. The API shape follows ASSIGNMENT appendix §A-§D.
 * Admin endpoints under /mock are intentionally unauthenticated: this is a
 * local demo tool, never a production service.
 */
final class Api
{
    public function __construct(private readonly State $state)
    {
    }

    /**
     * @return array{0:int,1:array<string,mixed>,2:int} HTTP status, JSON body, delay in ms
     */
    public function handle(string $method, string $path): array
    {
        try {
            return match (true) {
                $method === 'GET' && $path === '/health' => [200, ['status' => 'ok'], 0],
                $method === 'POST' && $path === '/subscription/send' => $this->subscription($this->body()),
                $method === 'POST' && $path === '/sms/send' => $this->sms($this->body()),
                $method === 'POST' && $path === '/caas/direct/debit' => $this->debit($this->body()),
                $method === 'POST' && $path === '/mock/debit-response' => $this->queueDebitResponse($this->body()),
                $method === 'POST' && $path === '/mock/config' => $this->configure($this->body()),
                $method === 'POST' && $path === '/mock/reset' => $this->reset(),
                $method === 'GET' && $path === '/mock/state' => [200, $this->summary(), 0],
                $method === 'POST' && $path === '/mock/callback' => $this->sendCallback($this->body()),
                default => [404, ['statusCode' => 'E404', 'statusDetail' => 'Not found'], 0],
            };
        } catch (InvalidArgumentException $exception) {
            return [422, ['statusCode' => 'E1002', 'statusDetail' => $exception->getMessage()], 0];
        } catch (Throwable $exception) {
            return [500, ['statusCode' => 'E1500', 'statusDetail' => $exception->getMessage()], 0];
        }
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Request body must be a JSON object');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $body @return array{0:int,1:array<string,mixed>,2:int} */
    private function subscription(array $body): array
    {
        if (!$this->authorized($body)) {
            return $this->unauthorized();
        }

        $action = (string) ($body['action'] ?? '');
        $subscriberId = (string) ($body['subscriberId'] ?? '');
        if (!in_array($action, ['0', '1'], true) || $subscriberId === '') {
            throw new InvalidArgumentException('action must be "0" or "1" and subscriberId is required');
        }

        $config = $this->state->read()['config'];
        $isOptIn = $action === '1';
        $statusCode = $isOptIn ? $config['optinStatus'] : $config['optoutStatus'];
        $delayMs = (int) ($isOptIn ? $config['optinDelayMs'] : $config['optoutDelayMs']);
        $subscriptionStatus = $isOptIn ? 'REGISTERED' : 'UNREGISTERED';

        $response = [
            'version' => '1.0',
            'requestId' => $this->requestId(),
            'statusCode' => $statusCode,
            'statusDetail' => $statusCode === 'S1000' ? 'SUCCESS' : 'Request failed',
        ];

        if ($statusCode === 'S1000') {
            $response['subscriptionStatus'] = $subscriptionStatus;
        }

        $this->state->mutate(function (array &$state) use ($action, $subscriberId, $statusCode, $response): void {
            $state['subscription_requests'][] = [
                'action' => $action,
                'subscriberId' => $subscriberId,
                'statusCode' => $statusCode,
                'response' => $response,
                'receivedAt' => $this->now(),
            ];
        });

        return [200, $response, $delayMs];
    }

    /** @param array<string,mixed> $body @return array{0:int,1:array<string,mixed>,2:int} */
    private function sms(array $body): array
    {
        if (!$this->authorized($body)) {
            return $this->unauthorized();
        }

        $destinations = $body['destinationAddresses'] ?? null;
        $message = (string) ($body['message'] ?? '');
        if (!is_array($destinations) || $destinations === [] || $message === '') {
            throw new InvalidArgumentException('destinationAddresses[] and message are required');
        }

        $config = $this->state->read()['config'];
        $statusCode = (string) $config['smsStatus'];
        $response = [
            'version' => '1.0',
            'requestId' => $this->requestId(),
            'statusCode' => $statusCode,
            'statusDetail' => $statusCode === 'S1000' ? 'Success' : 'Request failed',
        ];

        $this->state->mutate(function (array &$state) use ($body, $message, $destinations, $statusCode, $response): void {
            $request = [
                'destinationAddresses' => array_values(array_map('strval', $destinations)),
                'message' => $message,
                'sourceAddress' => (string) ($body['sourceAddress'] ?? ''),
                'statusCode' => $statusCode,
                'receivedAt' => $this->now(),
            ];
            $state['sms_requests'][] = $request;

            if ($statusCode === 'S1000') {
                foreach ($destinations as $destination) {
                    $state['sms'][] = [
                        'to' => (string) $destination,
                        'message' => $message,
                        'sourceAddress' => $request['sourceAddress'],
                        'requestId' => $response['requestId'],
                        'sentAt' => $request['receivedAt'],
                    ];
                }
            }
        });

        return [200, $response, 0];
    }

    /** @param array<string,mixed> $body @return array{0:int,1:array<string,mixed>,2:int} */
    private function debit(array $body): array
    {
        if (!$this->authorized($body)) {
            return $this->unauthorized();
        }

        $externalTrxId = (string) ($body['externalTrxId'] ?? '');
        $subscriberId = (string) ($body['subscriberId'] ?? '');
        $amount = $body['amount'] ?? null;
        $currency = (string) ($body['currency'] ?? '');

        if ($externalTrxId === '' || $subscriberId === '' || $currency === '') {
            throw new InvalidArgumentException('externalTrxId, subscriberId and currency are required');
        }
        if (!is_string($amount) || preg_match('/^\d+\.\d{2}$/', $amount) !== 1) {
            throw new InvalidArgumentException('amount must be a string with exactly two decimal places');
        }

        $delayMs = 0;

        $response = $this->state->mutate(function (array &$state) use ($externalTrxId, $subscriberId, $amount, $currency, &$delayMs): array {
            $requestIndex = count($state['debit_requests']);
            $request = [
                'externalTrxId' => $externalTrxId,
                'subscriberId' => $subscriberId,
                'amount' => $amount,
                'currency' => $currency,
                'receivedAt' => $this->now(),
                'respondedStatusCode' => null,
                'chargeCreated' => false,
            ];
            $state['debit_requests'][] = $request;

            // A completed charge is the only thing the platform remembers.
            // Failed E1326/E1318 attempts are not stored, so the same key can
            // be retried later and may succeed.
            if (isset($state['charges'][$externalTrxId])) {
                $original = $state['charges'][$externalTrxId];
                $state['debit_requests'][$requestIndex]['respondedStatusCode'] = 'E1337';

                if ($state['config']['duplicateDetails']) {
                    return [
                        'statusCode' => 'E1337',
                        'statusDetail' => 'Duplicate request',
                        'externalTrxId' => $externalTrxId,
                        'internalTrxId' => $original['internalTrxId'],
                        'referenceId' => $original['referenceId'],
                        'timeStamp' => $original['timeStamp'],
                    ];
                }

                return [
                    'statusCode' => 'E1337',
                    'statusDetail' => 'Duplicate request',
                    'externalTrxId' => $externalTrxId,
                ];
            }

            $descriptor = array_shift($state['debit_queue']) ?? [
                'statusCode' => 'S1000',
                'statusDetail' => 'Request was successfully processed',
                'delayMs' => 0,
            ];
            $delayMs = (int) ($descriptor['delayMs'] ?? 0);
            $statusCode = (string) ($descriptor['statusCode'] ?? 'S1000');

            if ($statusCode !== 'S1000') {
                $state['debit_requests'][$requestIndex]['respondedStatusCode'] = $statusCode;

                return [
                    'statusCode' => $statusCode,
                    'statusDetail' => (string) ($descriptor['statusDetail'] ?? 'Request failed'),
                    'externalTrxId' => $externalTrxId,
                ];
            }

            $charge = [
                'externalTrxId' => $externalTrxId,
                'internalTrxId' => $this->digits(15),
                'referenceId' => $this->digits(8),
                'subscriberId' => $subscriberId,
                'amount' => $amount,
                'currency' => $currency,
                'timeStamp' => $this->now(),
                'requestIndex' => $requestIndex,
            ];
            $state['charges'][$externalTrxId] = $charge;
            $state['debit_requests'][$requestIndex]['chargeCreated'] = true;
            $state['debit_requests'][$requestIndex]['respondedStatusCode'] = 'S1000';

            return [
                'statusCode' => 'S1000',
                'statusDetail' => 'Request was successfully processed',
                'externalTrxId' => $externalTrxId,
                'internalTrxId' => $charge['internalTrxId'],
                'referenceId' => $charge['referenceId'],
                'timeStamp' => $charge['timeStamp'],
            ];
        });

        return [200, $response, $delayMs];
    }

    /** @param array<string,mixed> $body @return array{0:int,1:array<string,mixed>,2:int} */
    private function queueDebitResponse(array $body): array
    {
        $statusCode = (string) ($body['statusCode'] ?? '');
        if ($statusCode === '') {
            throw new InvalidArgumentException('statusCode is required');
        }

        $count = max(1, min(20, (int) ($body['count'] ?? 1)));
        $descriptor = [
            'statusCode' => $statusCode,
            'statusDetail' => (string) ($body['statusDetail'] ?? ($statusCode === 'S1000' ? 'Request was successfully processed' : 'Request failed')),
            'delayMs' => max(0, (int) ($body['delayMs'] ?? 0)),
        ];

        $queued = $this->state->mutate(function (array &$state) use ($descriptor, $count): int {
            for ($i = 0; $i < $count; $i++) {
                $state['debit_queue'][] = $descriptor;
            }

            return count($state['debit_queue']);
        });

        return [200, ['queued' => $count, 'queueLength' => $queued], 0];
    }

    /** @param array<string,mixed> $body @return array{0:int,1:array<string,mixed>,2:int} */
    private function configure(array $body): array
    {
        $allowed = ['duplicateDetails', 'optinStatus', 'optinDelayMs', 'optoutStatus', 'optoutDelayMs', 'smsStatus'];
        $config = $this->state->mutate(function (array &$state) use ($body, $allowed): array {
            foreach ($allowed as $key) {
                if (array_key_exists($key, $body)) {
                    $state['config'][$key] = $body[$key];
                }
            }

            return $state['config'];
        });

        return [200, ['config' => $config], 0];
    }

    /** @return array{0:int,1:array<string,mixed>,2:int} */
    private function reset(): array
    {
        $this->state->mutate(function (array &$state): void {
            $state = State::defaults();
        });

        return [200, ['reset' => true], 0];
    }

    /** @return array<string,mixed> */
    private function summary(): array
    {
        $state = $this->state->read();

        return [
            'config' => $state['config'],
            'debitQueueLength' => count($state['debit_queue']),
            'debitRequestCount' => count($state['debit_requests']),
            'chargeCount' => count($state['charges']),
            'charges' => array_values($state['charges']),
            'debitRequests' => $state['debit_requests'],
            'sms' => $state['sms'],
            'smsRequestCount' => count($state['sms_requests']),
            'subscriptionRequests' => $state['subscription_requests'],
            'callbacks' => $state['callbacks'],
        ];
    }

    /** @param array<string,mixed> $body @return array{0:int,1:array<string,mixed>,2:int} */
    private function sendCallback(array $body): array
    {
        $subscriberId = (string) ($body['subscriberId'] ?? '');
        $status = (string) ($body['status'] ?? '');
        if ($subscriberId === '' || !in_array($status, ['REGISTERED', 'UNREGISTERED', 'PENDING', 'CHARGE'], true)) {
            throw new InvalidArgumentException('valid subscriberId and status are required');
        }

        $url = getenv('SERVICE_WEBHOOK_URL') ?: 'http://service:8000/api/webhooks/subscription';
        $repeat = max(1, min(10, (int) ($body['repeat'] ?? 1)));
        $payload = [
            'applicationId' => getenv('APP_ID') ?: 'APP_000123',
            'subscriberId' => $subscriberId,
            'frequency' => (string) ($body['frequency'] ?? 'DAILY'),
            'status' => $status,
            'version' => '1.0',
            'timeStamp' => (string) ($body['timeStamp'] ?? $this->now()),
        ];

        $results = [];
        for ($i = 0; $i < $repeat; $i++) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                    'content' => json_encode($payload),
                    'timeout' => 5,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($url, false, $context);
            $httpCode = 0;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $httpCode = (int) $m[1];
            }
            $results[] = ['httpStatus' => $httpCode, 'body' => $raw === false ? null : json_decode($raw, true)];
        }

        $this->state->mutate(function (array &$state) use ($payload, $results, $url): void {
            $state['callbacks'][] = [
                'url' => $url,
                'payload' => $payload,
                'results' => $results,
                'sentAt' => $this->now(),
            ];
        });

        return [200, ['pushed' => $results, 'payload' => $payload], 0];
    }

    /** @param array<string,mixed> $body */
    private function authorized(array $body): bool
    {
        $appId = getenv('APP_ID') ?: 'APP_000123';
        $password = getenv('APP_PASSWORD') ?: 'demo-password-not-a-secret';

        return hash_equals($appId, (string) ($body['applicationId'] ?? ''))
            && hash_equals($password, (string) ($body['password'] ?? ''));
    }

    /** @return array{0:int,1:array<string,mixed>,2:int} */
    private function unauthorized(): array
    {
        return [401, ['statusCode' => 'E1001', 'statusDetail' => 'Invalid credentials'], 0];
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP');
    }

    private function requestId(): string
    {
        return (string) random_int(1000000000000, 9999999999999);
    }

    private function digits(int $length): string
    {
        $value = (string) random_int(1, 9);
        for ($i = 1; $i < $length; $i++) {
            $value .= (string) random_int(0, 9);
        }

        return $value;
    }
}
