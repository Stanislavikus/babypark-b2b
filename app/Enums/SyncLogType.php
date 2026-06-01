<?php

namespace App\Enums;

enum SyncLogType: string
{
    case Products = 'products';
    case Prices = 'prices';
    case Stocks = 'stocks';
    case Contractors = 'contractors';
    case Statuses = 'statuses';

    public function label(): string
    {
        return match ($this) {
            self::Products => 'Товари',
            self::Prices => 'Ціни',
            self::Stocks => 'Залишки',
            self::Contractors => 'Контрагенти',
            self::Statuses => 'Статуси замовлень',
        };
    }
}
