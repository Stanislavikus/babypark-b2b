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

    public static function invalidNumericPayload(): self
    {
        return new self('Set payload for a number/decimal field must be an int or base-10 decimal string.');
    }

    public static function floatNumericPayload(): self
    {
        return new self('Set payload for a number/decimal field must not be a PHP float; use an int or base-10 decimal string.');
    }

    public static function invalidBooleanPayload(): self
    {
        return new self('Set payload for a boolean field must be an actual bool.');
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

    public static function duplicateMultiSelectOption(string $option): self
    {
        return new self("Set payload for a multi-select field must not contain duplicate option code '{$option}'.");
    }
}
