<?php

namespace App\Exceptions\Catalog;

use RuntimeException;

final class MasterProductLifecycleMutationException extends RuntimeException
{
    public static function sourceOwnedReadOnly(): self
    {
        return new self('Для товару або варіанта з джерелом 1С стан у Master доступний лише для перегляду.');
    }

    public static function staleState(): self
    {
        return new self('Стан товару змінився після відкриття картки. Оновіть сторінку і повторіть дію.');
    }
}
