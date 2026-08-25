<?php

namespace App\Services\Fields\Exceptions;

use App\Enums\AttributeDataType;

final class UnsupportedFieldDataTypeException extends FieldValueWriterException
{
    public static function forType(AttributeDataType $type, string $fieldDefinitionId): self
    {
        return new self(
            "Data type {$type->value} on field definition {$fieldDefinitionId} is not supported by the GAP-028A writer. "
            .'Supported types in this slice: text, long_text, select (single-value, non-multi).'
        );
    }
}
