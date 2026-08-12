<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class FieldMappingConflictException extends RuntimeException
{
    public static function internalTargetAlreadyMapped(string $fieldBindingId, ?\Throwable $previous = null): self
    {
        return new self(
            "Field binding {$fieldBindingId} is already mapped in this sync configuration.",
            previous: $previous,
        );
    }

    public static function externalFieldAlreadyMapped(string $externalFieldKey, ?\Throwable $previous = null): self
    {
        return new self(
            "External field key {$externalFieldKey} is already mapped in this sync configuration.",
            previous: $previous,
        );
    }
}
