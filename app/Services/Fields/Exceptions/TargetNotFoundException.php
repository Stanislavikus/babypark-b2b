<?php

namespace App\Services\Fields\Exceptions;

final class TargetNotFoundException extends FieldValueWriterException
{
    public static function product(int|string $productId): self
    {
        return new self("Product {$productId} not found.");
    }

    public static function variant(int|string $variantId): self
    {
        return new self("ProductVariant {$variantId} not found.");
    }
}
