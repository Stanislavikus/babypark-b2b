<?php

namespace App\Services\Fields\Exceptions;

final class DynamicFieldCurrentValueMismatchException extends FieldValueWriterException
{
    public static function forField(string $fieldCode): self
    {
        return new self("Current value for dynamic field '{$fieldCode}' no longer matches the expected value.");
    }
}
