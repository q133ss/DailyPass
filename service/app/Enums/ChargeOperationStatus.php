<?php

namespace App\Enums;

enum ChargeOperationStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case RetryScheduled = 'RETRY_SCHEDULED';
    case Succeeded = 'SUCCEEDED';
    case FailedPermanent = 'FAILED_PERMANENT';
    case FailedExhausted = 'FAILED_EXHAUSTED';
    case Unknown = 'UNKNOWN';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::FailedPermanent,
            self::FailedExhausted,
            self::Unknown,
            self::Expired,
            self::Cancelled,
        ], true);
    }
}
