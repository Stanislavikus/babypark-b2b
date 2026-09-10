<?php

namespace App\Exceptions\Catalog;

use RuntimeException;

final class InvalidColumnFieldValueException extends RuntimeException
{
    public static function nullSetPayload(string $fieldCode): self
    {
        return new self("Set payload for column-backed field '{$fieldCode}' must not be null. Use clear() only where that field allows it.");
    }

    public static function nonStringPayload(string $fieldCode): self
    {
        return new self("Set payload for column-backed field '{$fieldCode}' must be a PHP string.");
    }

    public static function emptyName(): self
    {
        return new self("Set payload for column-backed field 'name' must not be an empty string.");
    }

    public static function whitespaceOnlyName(): self
    {
        return new self("Set payload for column-backed field 'name' must not be whitespace-only.");
    }

    public static function nameTooLong(int $maxLength): self
    {
        return new self("Set payload for column-backed field 'name' exceeds VARCHAR({$maxLength}) capacity.");
    }

    public static function descriptionTooLong(int $maxBytes): self
    {
        return new self("Set payload for column-backed field 'description' exceeds TEXT capacity of {$maxBytes} bytes.");
    }
}
