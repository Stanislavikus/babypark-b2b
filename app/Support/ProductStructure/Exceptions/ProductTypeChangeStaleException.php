<?php

namespace App\Support\ProductStructure\Exceptions;

use RuntimeException;

final class ProductTypeChangeStaleException extends RuntimeException
{
    public static function previewIsStale(): self
    {
        return new self('Product type change preview is stale. Refresh the impact preview before applying.');
    }
}
