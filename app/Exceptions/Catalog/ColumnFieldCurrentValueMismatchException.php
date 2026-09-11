<?php

namespace App\Exceptions\Catalog;

use RuntimeException;

final class ColumnFieldCurrentValueMismatchException extends RuntimeException
{
    public static function forField(string $fieldCode): self
    {
        return new self("Current value for column-backed field '{$fieldCode}' no longer matches the expected value.");
    }
}
