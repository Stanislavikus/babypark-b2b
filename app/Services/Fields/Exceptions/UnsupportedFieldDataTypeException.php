<?php

namespace App\Services\Fields\Exceptions;

use App\Enums\AttributeDataType;

final class UnsupportedFieldDataTypeException extends FieldValueWriterException
{
    public static function forType(AttributeDataType $type, string $fieldDefinitionId): self
    {
        return new self(
            "Data type {$type->value} on field definition {$fieldDefinitionId} is not supported by the governed dynamic field-value writer. "
            .'Supported types in this slice: text, long_text, number, decimal, boolean, date, select, multi_select, url.'
        );
    }
}
