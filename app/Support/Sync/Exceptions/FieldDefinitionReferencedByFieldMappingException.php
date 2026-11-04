<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class FieldDefinitionReferencedByFieldMappingException extends RuntimeException
{
    public static function forDefinition(string $fieldDefinitionId): self
    {
        return new self(
            "Field definition {$fieldDefinitionId} is referenced by a confirmed connector field mapping and cannot be deleted.",
        );
    }
}
