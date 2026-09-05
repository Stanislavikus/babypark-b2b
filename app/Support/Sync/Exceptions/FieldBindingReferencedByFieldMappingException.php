<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class FieldBindingReferencedByFieldMappingException extends RuntimeException
{
    public static function forBinding(string $fieldBindingId): self
    {
        return new self(
            "Field binding {$fieldBindingId} is referenced by a confirmed connector field mapping and cannot be deleted.",
        );
    }
}
