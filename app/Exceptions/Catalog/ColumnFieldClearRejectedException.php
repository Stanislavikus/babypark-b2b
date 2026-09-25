<?php

namespace App\Exceptions\Catalog;

use RuntimeException;

final class ColumnFieldClearRejectedException extends RuntimeException
{
    public static function forField(string $fieldCode): self
    {
        return new self("Clear() is not permitted for required/non-null column-backed field '{$fieldCode}'.");
    }
}
