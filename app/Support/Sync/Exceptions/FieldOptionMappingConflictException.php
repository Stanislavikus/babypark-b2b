<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class FieldOptionMappingConflictException extends RuntimeException
{
    public static function internalOptionAlreadyMapped(
        string $internalOptionKey,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            "Internal option '{$internalOptionKey}' is already mapped for this field mapping.",
            previous: $previous,
        );
    }
}
