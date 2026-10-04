<?php

namespace App\Exceptions\Pricing;

use RuntimeException;

final class MasterOfferMutationException extends RuntimeException
{
    public static function invalidSellPrice(): self
    {
        return new self('Ціна продажу має бути більшою за нуль.');
    }

    public static function invalidCompareAtPrice(): self
    {
        return new self('Ціна до знижки має бути більшою за поточну ціну продажу.');
    }

    public static function invalidCostPrice(): self
    {
        return new self('Собівартість не може бути від’ємною.');
    }

    public static function defaultPriceListUnavailable(): self
    {
        return new self('Основний прайс компанії недоступний або налаштований неоднозначно.');
    }

    public static function variantUnavailable(): self
    {
        return new self('Варіант уже недоступний. Оновіть товар і повторіть дію.');
    }

    public static function sourceOwnedReadOnly(): self
    {
        return new self('Для товару з джерелом 1С ціна в Master поки доступна лише для перегляду.');
    }

    public static function advancedPriceListState(): self
    {
        return new self('Ціна цього варіанта керується розширеним правилом прайс-листа. Відредагуйте її у прайс-листі.');
    }

    public static function legacyInvalidSalePrice(): self
    {
        return new self('Поточна акційна ціна потребує перевірки перед редагуванням у Master.');
    }

    public static function staleOffer(): self
    {
        return new self('Ціна змінилася після відкриття форми. Оновіть товар і повторіть дію.');
    }
}
