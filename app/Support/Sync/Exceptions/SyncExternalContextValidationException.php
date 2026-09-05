<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class SyncExternalContextValidationException extends RuntimeException
{
    public static function invalidPayload(string $message): self
    {
        return new self($message);
    }
}
