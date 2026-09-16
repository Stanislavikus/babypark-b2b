<?php

namespace App\Services\Sync\Exceptions;

final class InvalidSyncProductSelectionException extends \RuntimeException
{
    /** @param list<int> $productIds */
    public static function productsOutsideWorkspace(array $productIds): self
    {
        return new self('Product selection contains unavailable workspace products: '.implode(', ', $productIds));
    }

    public static function unsupportedDomain(): self
    {
        return new self('Product selection is available only for the Products sync domain.');
    }
}
