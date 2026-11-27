<?php

namespace App\Services\Fields\Exceptions;

final class FieldDefinitionNotFoundException extends FieldValueWriterException
{
    public static function forId(string $fieldDefinitionId): self
    {
        return new self("Field definition {$fieldDefinitionId} not found.");
    }
}
