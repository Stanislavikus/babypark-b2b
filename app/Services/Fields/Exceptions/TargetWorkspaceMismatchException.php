<?php

namespace App\Services\Fields\Exceptions;

final class TargetWorkspaceMismatchException extends FieldValueWriterException
{
    public static function product(int|string $productId, string $expected, string $actual): self
    {
        return new self("Product {$productId} belongs to workspace {$actual}, not {$expected}.");
    }

    public static function variant(int|string $variantId, string $expected, string $actual): self
    {
        return new self("ProductVariant {$variantId} belongs to workspace {$actual}, not {$expected}.");
    }
}
