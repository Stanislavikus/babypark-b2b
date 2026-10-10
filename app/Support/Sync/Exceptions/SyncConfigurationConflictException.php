<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class SyncConfigurationConflictException extends RuntimeException
{
    public static function duplicateIdentity(?\Throwable $previous = null): self
    {
        return new self(
            'A sync configuration already exists for this account, data domain, and external context.',
            previous: $previous,
        );
    }
}
