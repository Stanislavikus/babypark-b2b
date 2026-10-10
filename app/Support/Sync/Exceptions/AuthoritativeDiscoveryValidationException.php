<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class AuthoritativeDiscoveryValidationException extends RuntimeException
{
    public static function noAuthoritativeSnapshot(): self
    {
        return new self('No authoritative discovery snapshot is available for this connector account.');
    }

    public static function discoverySourceUnavailable(string $reason): self
    {
        return new self("Authoritative discovery source is unavailable: {$reason}.");
    }

    public static function externalFieldKeyAbsent(string $externalFieldKey): self
    {
        return new self(
            "External field key {$externalFieldKey} is absent from the authoritative discovery snapshot.",
        );
    }
}
