<?php

namespace App\Services\Fields\Exceptions;

final class MultiValueNotSupportedException extends FieldValueWriterException
{
    public static function forDefinition(string $fieldDefinitionId): self
    {
        return new self(
            "Field definition {$fieldDefinitionId} is multi-value; the GAP-028A writer only supports single-value writes."
        );
    }
}
