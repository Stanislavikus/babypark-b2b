<?php

namespace App\Exceptions\Catalog;

use RuntimeException;

class InvalidTagBulkSelectionException extends RuntimeException
{
    public const REASON_EMPTY_PRODUCTS = 'empty_products';

    public const REASON_EMPTY_TAGS = 'empty_tags';

    public const REASON_PRODUCT_NOT_FOUND = 'product_not_found';

    public const REASON_TAG_NOT_FOUND = 'tag_not_found';

    public const REASON_PRODUCT_CROSS_WORKSPACE = 'product_cross_workspace';

    public const REASON_TAG_CROSS_WORKSPACE = 'tag_cross_workspace';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function emptyProducts(): self
    {
        return new self(
            self::REASON_EMPTY_PRODUCTS,
            'Оберіть хоча б один товар.',
        );
    }

    public static function emptyTags(): self
    {
        return new self(
            self::REASON_EMPTY_TAGS,
            'Оберіть хоча б один тег.',
        );
    }

    public static function productNotFound(int|string $productId): self
    {
        return new self(
            self::REASON_PRODUCT_NOT_FOUND,
            "Товар з ідентифікатором {$productId} не знайдено.",
        );
    }

    public static function tagNotFound(string $tagId): self
    {
        return new self(
            self::REASON_TAG_NOT_FOUND,
            "Тег з ідентифікатором {$tagId} не знайдено.",
        );
    }

    public static function productCrossWorkspace(int|string $productId): self
    {
        return new self(
            self::REASON_PRODUCT_CROSS_WORKSPACE,
            "Товар {$productId} належить іншому робочому простору.",
        );
    }

    public static function tagCrossWorkspace(string $tagId): self
    {
        return new self(
            self::REASON_TAG_CROSS_WORKSPACE,
            "Тег {$tagId} належить іншому робочому простору.",
        );
    }
}
