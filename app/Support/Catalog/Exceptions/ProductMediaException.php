<?php

namespace App\Support\Catalog\Exceptions;

use RuntimeException;

final class ProductMediaException extends RuntimeException
{
    public static function invalidImage(): self
    {
        return new self('Файл не є підтримуваним зображенням.');
    }

    public static function incompleteOrder(): self
    {
        return new self('Порядок медіа застарів або неповний. Оновіть товар і повторіть дію.');
    }

    public static function mediaNotFound(): self
    {
        return new self('Медіа більше не належить цьому товару.');
    }

    public static function invalidOriginal(): self
    {
        return new self('Галерея може посилатися лише на Original MediaAsset.');
    }

    public static function legacyWriteForbidden(): self
    {
        return new self('products.images є compatibility projection після переходу на Master Media і не може редагуватися напряму.');
    }

    public static function legacyMediaNeedsRepair(): self
    {
        return new self('Legacy-медіа цього товару ще не переведено у Master Media. Спочатку потрібне безпечне відновлення галереї.');
    }
}
