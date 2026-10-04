<?php

namespace App\Exceptions\Availability;

use RuntimeException;

final class InventoryMutationException extends RuntimeException
{
    public static function invalidQuantity(): self
    {
        return new self('Залишок не може бути від’ємним.');
    }

    public static function variantUnavailable(): self
    {
        return new self('Варіант більше не належить цьому товару.');
    }

    public static function staleQuantity(): self
    {
        return new self('Залишок уже змінився. Оновіть товар і повторіть дію.');
    }

    public static function sourceOwnedReadOnly(): self
    {
        return new self('Для товару або варіанта з джерелом 1С залишок у Master доступний лише для перегляду.');
    }

    public static function reconciliationRequired(): self
    {
        return new self('Залишок потребує звірки перед редагуванням.');
    }

    public static function multipleLocationsReadOnly(): self
    {
        return new self('Залишок для кількох локацій зараз доступний лише для перегляду.');
    }

    public static function defaultLocationUnavailable(): self
    {
        return new self('Не вдалося однозначно визначити основну локацію для залишку.');
    }
}
