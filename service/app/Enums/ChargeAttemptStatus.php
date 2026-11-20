<?php

namespace App\Enums;

enum ChargeAttemptStatus: string
{
    case Pending = 'PENDING';
    case Succeeded = 'SUCCEEDED';
    case FailedRetryable = 'FAILED_RETRYABLE';
    case FailedPermanent = 'FAILED_PERMANENT';
    case DuplicateConfirmed = 'DUPLICATE_CONFIRMED';
    case Timeout = 'TIMEOUT';
    case Unknown = 'UNKNOWN';
}
