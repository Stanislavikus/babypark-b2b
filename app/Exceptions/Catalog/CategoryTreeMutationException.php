<?php

namespace App\Exceptions\Catalog;

use RuntimeException;

final class CategoryTreeMutationException extends RuntimeException
{
    public static function invalidName(): self
    {
        return new self('Назва категорії обов’язкова.');
    }

    public static function invalidParent(): self
    {
        return new self('Батьківська категорія має належати цьому робочому простору.');
    }

    public static function cycle(): self
    {
        return new self('Категорію не можна перемістити всередину самої себе або її підкатегорії.');
    }

    public static function staleTree(): self
    {
        return new self('Дерево категорій змінилося після відкриття сторінки. Оновіть дерево і повторіть дію.');
    }

    public static function invalidTreePayload(): self
    {
        return new self('Структура дерева категорій некоректна. Зміни не збережено.');
    }

    public static function invalidDeleteDestination(): self
    {
        return new self('Цільова категорія має бути активною категорією цього робочого простору.');
    }

    public static function staleDeleteImpact(): self
    {
        return new self('Категорія змінилася після відкриття підтвердження. Оновіть сторінку і повторіть видалення.');
    }
}
