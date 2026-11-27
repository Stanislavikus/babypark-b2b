<?php

namespace App\Filament\Resources\ProductResource\Support;

use RuntimeException;

final class ProductWorkspaceFieldEditStaleException extends RuntimeException
{
    public static function snapshotMissing(): self
    {
        return new self('Редактор полів товару застарів. Відкрийте його ще раз.');
    }

    public static function structureChanged(): self
    {
        return new self('Структура товару або набір варіантів змінилися. Відкрийте поля товару ще раз.');
    }
}
