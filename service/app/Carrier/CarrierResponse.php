<?php

namespace App\Carrier;

final class CarrierResponse
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly ?string $statusCode,
        public readonly ?string $statusDetail,
        public readonly array $payload = [],
        public readonly bool $transportError = false,
        public readonly bool $timedOut = false,
        public readonly ?string $error = null,
    ) {}

    public function successful(): bool
    {
        return ! $this->transportError && $this->statusCode === 'S1000';
    }

    public function field(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** @return array<string, mixed> */
    public function toAuditArray(): array
    {
        return [
            'statusCode' => $this->statusCode,
            'statusDetail' => $this->statusDetail,
            'payload' => $this->payload,
            'transportError' => $this->transportError,
            'timedOut' => $this->timedOut,
            'error' => $this->error,
        ];
    }
}
