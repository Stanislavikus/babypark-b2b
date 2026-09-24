<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class AdobeProductClassificationException extends RuntimeException
{
    public static function nonAdobeAccount(): self
    {
        return new self('Magento product classification requires an Adobe Commerce connector account.');
    }

    public static function productTypeUnavailable(): self
    {
        return new self('ProductType is unavailable for Magento classification in this workspace.');
    }

    public static function productUnavailable(): self
    {
        return new self('Product is unavailable for Magento classification in this workspace.');
    }

    public static function attributeSetUnavailable(): self
    {
        return new self('Adobe Attribute Set is unavailable for this connector account.');
    }

    public static function categoriesRequired(): self
    {
        return new self('At least one external Magento category is required for an explicit Product category override.');
    }
}
