<?php

namespace App\Services\Fields\Exceptions;

use App\Enums\AttributeDataType;

final class UnsupportedFieldValidationRulesException extends FieldValueWriterException
{
    public static function forDefinition(AttributeDataType $type, string $fieldDefinitionId): self
    {
        return new self(
            "Field definition {$fieldDefinitionId} declares validation_rules that are not supported by the governed dynamic field-value writer for data type {$type->value}."
        );
    }
}
