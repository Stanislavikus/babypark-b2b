<?php

namespace App\Services\Fields\Exceptions;

use App\Enums\FieldObjectType;

final class UnsupportedFieldObjectTypeException extends FieldValueWriterException
{
    public static function forType(FieldObjectType $type): self
    {
        return new self(
            "Field object type {$type->value} is not supported by the GAP-028A writer. Supported target types in this slice: product, product_variant."
        );
    }
}
