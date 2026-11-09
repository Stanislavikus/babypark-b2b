<?php

namespace App\Support\Catalog\Exceptions;

use RuntimeException;

final class VariantMediaException extends RuntimeException
{
    public static function variantUnavailable(): self
    {
        return new self('Варіант більше не належить цьому товару.');
    }

    public static function assetUnavailable(): self
    {
        return new self('Одне або кілька медіа недоступні для цього товару.');
    }

    public static function invalidOriginal(): self
    {
        return new self('Медіа варіанта може посилатися лише на Original MediaAsset.');
    }

    public static function incompleteOrder(): self
    {
        return new self('Порядок медіа варіанта застарів або неповний. Оновіть товар і повторіть дію.');
    }

    public static function mediaNotFound(): self
    {
        return new self('Медіа більше не призначене цьому варіанту.');
    }

    public static function explicitPrimaryRequired(): self
    {
        return new self('Головне фото варіанта змінюється лише окремою явною дією.');
    }
}
