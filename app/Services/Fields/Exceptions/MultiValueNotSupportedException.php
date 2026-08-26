<?php

namespace App\Services\Fields\Exceptions;

final class MultiValueNotSupportedException extends FieldValueWriterException
{
    public static function forDefinition(string $fieldDefinitionId): self
    {
        return new self(
            "Field definition {$fieldDefinitionId} is multi-value; this governed dynamic writer path only supports single-value writes for that datatype."
        );
    }

    public static function singleValueRequired(string $fieldDefinitionId, string $dataType): self
    {
        return new self(
            "Field definition {$fieldDefinitionId} declares data type {$dataType}, which requires is_multi_value = false in the governed dynamic writer."
        );
    }

    public static function multiValueRequired(string $fieldDefinitionId, string $dataType): self
    {
        return new self(
            "Field definition {$fieldDefinitionId} declares data type {$dataType}, which requires is_multi_value = true in the governed dynamic writer."
        );
    }
}
