<?php

namespace App\Support;

use InvalidArgumentException;

final class SubscriberAliasResolver
{
    /**
     * Resolve the public MSISDN to the opaque id used with the carrier.
     * The resolver never tries to parse a phone number out of an alias.
     */
    public function resolve(string $msisdn): string
    {
        $alias = config('billing.aliases', [])[$msisdn] ?? $msisdn;

        if (! is_string($alias) || trim($alias) === '') {
            throw new InvalidArgumentException('Subscriber alias is empty');
        }

        return $alias;
    }
}
