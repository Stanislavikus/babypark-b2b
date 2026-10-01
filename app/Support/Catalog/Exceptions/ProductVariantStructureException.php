<?php

namespace App\Support\Catalog\Exceptions;

use RuntimeException;

final class ProductVariantStructureException extends RuntimeException
{
    public static function sourceOwned(): self
    {
        return new self('Структура цього товару керується зовнішнім джерелом і не може змінюватися тут.');
    }

    public static function notSimple(): self
    {
        return new self('Додавання першої опції доступне лише для товару з одним варіантом.');
    }

    public static function invalidAxis(): self
    {
        return new self('Це поле не може бути опцією варіантів для поточного типу товару.');
    }

    public static function invalidOption(): self
    {
        return new self('Обране значення опції недоступне.');
    }

    public static function incompleteAssignments(): self
    {
        return new self('Потрібно вказати значення для всіх варіантів і всіх оголошених опцій.');
    }

    public static function duplicateCombination(): self
    {
        return new self('Варіант із такою комбінацією опцій уже існує.');
    }

    public static function duplicateSku(): self
    {
        return new self('Такий SKU уже використовується в цьому Workspace.');
    }

    public static function additionalValuesRequired(): self
    {
        return new self('Додайте хоча б одне нове значення для першої опції.');
    }

    public static function axesAlreadyDeclared(): self
    {
        return new self('Перша опція вже оголошена. Додайте наступну опцію або окремий варіант.');
    }

    public static function axesRequired(): self
    {
        return new self('Спочатку додайте опцію варіантів.');
    }

    public static function inactiveVariantsUnsupported(): self
    {
        return new self('Товар має неактивні історичні варіанти. Зміна осей потребує окремого lifecycle-рішення.');
    }

    public static function declaredAxisBlocksStructureChange(): self
    {
        return new self('Поле використовується як опція варіантів у товарі. Спочатку змініть структуру варіантів товару.');
    }

    public static function declaredAxesBlockTypeChange(): self
    {
        return new self('Для товару вже оголошені опції варіантів. Зміну типу товару потрібно виконувати окремим керованим переходом.');
    }
}
