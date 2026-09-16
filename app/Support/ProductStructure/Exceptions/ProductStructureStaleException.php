<?php

namespace App\Support\ProductStructure\Exceptions;

use RuntimeException;

final class ProductStructureStaleException extends RuntimeException
{
    public static function revisionMismatch(): self
    {
        return new self('Product structure changed since it was loaded. Refresh before applying this change.');
    }
}
