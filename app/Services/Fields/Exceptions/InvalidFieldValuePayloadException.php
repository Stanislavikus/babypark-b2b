<?php

namespace App\Services\Fields\Exceptions;

final class InvalidFieldValuePayloadException extends FieldValueWriterException
{
    public static function nullPayload(): self
    {
        return new self('Set payload must not be null. Use Clear() to remove a value.');
    }

    public static function nonStringTextPayload(): self
    {
        return new self('Set payload for a text/long_text field must be a string.');
    }

    public static function nonStringSelectPayload(): self
    {
        return new self('Set payload for a single-value select field must be a string (stable option code).');
    }
}
