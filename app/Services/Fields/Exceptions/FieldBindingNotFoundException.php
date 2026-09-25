<?php

namespace App\Services\Fields\Exceptions;

final class FieldBindingNotFoundException extends FieldValueWriterException
{
    public static function forId(string $fieldBindingId): self
    {
        return new self("Field binding {$fieldBindingId} not found.");
    }
}
