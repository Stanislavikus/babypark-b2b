<?php

namespace App\Services\Fields\Exceptions;

use App\Enums\AttributeStorageType;

final class FieldBindingStorageTypeMismatchException extends FieldValueWriterException
{
    public static function forId(string $fieldBindingId, AttributeStorageType $actual): self
    {
        return new self(
            "Field binding {$fieldBindingId} uses storage_type {$actual->value}; the governed dynamic field-value writer only handles dynamic storage."
        );
    }
}
