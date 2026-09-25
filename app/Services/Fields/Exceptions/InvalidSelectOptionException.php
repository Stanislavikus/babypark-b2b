<?php

namespace App\Services\Fields\Exceptions;

final class InvalidSelectOptionException extends FieldValueWriterException
{
    public static function forValue(string $internalOptionKey, string $fieldDefinitionId): self
    {
        return new self(
            "Option '{$internalOptionKey}' is not allowed for select field definition {$fieldDefinitionId}."
        );
    }

    public static function optionsUndeclared(string $fieldDefinitionId): self
    {
        return new self(
            "Select field definition {$fieldDefinitionId} has no declared options in validation_rules; refusing to accept arbitrary values."
        );
    }
}
