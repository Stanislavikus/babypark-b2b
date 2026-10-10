<?php

namespace App\Support\Media\Exceptions;

use RuntimeException;

final class MediaIngestException extends RuntimeException
{
    public static function invalidImage(): self
    {
        return new self('Файл не є підтримуваним зображенням.');
    }

    public static function fileTooLarge(): self
    {
        return new self('Файл завеликий. Максимальний розмір Original — 20 МіБ.');
    }

    public static function pixelLimitExceeded(): self
    {
        return new self('Зображення завелике за роздільною здатністю. Максимум — 25 мегапікселів.');
    }

    public static function svgUnsupported(): self
    {
        return new self('SVG поки не підтримується. Завантажте PNG, JPEG або WebP.');
    }

    public static function derivativeHashConflict(): self
    {
        return new self('Такий файл уже існує як технічна похідна версія. Завантажте вихідний Original.');
    }

    public static function storageFailed(): self
    {
        return new self('Не вдалося зберегти Original медіафайлу.');
    }
}
