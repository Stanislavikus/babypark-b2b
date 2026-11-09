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

    public static function invalidIntegerPayload(): self
    {
        return new self('Set payload for a Number field must be a PHP int or canonical base-10 integer string within DECIMAL(20,6) range.');
    }

    public static function invalidDecimalPayload(): self
    {
        return new self('Set payload for a Decimal field must be a PHP int or exact base-10 decimal string within DECIMAL(20,6) range.');
    }

    public static function floatPayloadNotAllowed(string $type): self
    {
        return new self("Set payload for a {$type} field must not be a PHP float.");
    }

    public static function invalidBooleanPayload(): self
    {
        return new self('Set payload for a boolean field must be an actual PHP bool.');
    }

    public static function invalidDatePayload(): self
    {
        return new self('Set payload for a date field must be a real calendar date string in YYYY-MM-DD format.');
    }

    public static function invalidUrlPayload(): self
    {
        return new self('Set payload for a url field must be a non-empty absolute http/https URL string with a host.');
    }

    public static function invalidMultiSelectPayload(): self
    {
        return new self('Set payload for a multi-select field must be a non-empty list of stable option-code strings.');
    }

    public static function duplicateMultiSelectCode(string $code): self
    {
        return new self("Set payload for a multi-select field must not contain duplicate option code '{$code}'.");
    }
}
