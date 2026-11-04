<?php

namespace App\Services\Fields\Exceptions;

use App\Enums\FieldObjectType;

final class FieldBindingObjectTypeMismatchException extends FieldValueWriterException
{
    public static function forId(string $fieldBindingId, FieldObjectType $expected, FieldObjectType $actual): self
    {
        return new self(
            "Field binding {$fieldBindingId} targets {$actual->value}, not {$expected->value}."
        );
    }
}
