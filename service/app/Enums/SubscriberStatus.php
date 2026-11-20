<?php

namespace App\Enums;

enum SubscriberStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Suspended = 'SUSPENDED';

    public function canBeCharged(): bool
    {
        return $this === self::Active;
    }
}
