<?php

namespace App\Enums;

enum SyncLogType: string
{
    case Products = 'products';
    case Prices = 'prices';
    case Stocks = 'stocks';
    case Customers = 'customers';
    case Statuses = 'statuses';

    public function label(): string
    {
        return match ($this) {
            self::Products => 'Товари',
            self::Prices => 'Ціни',
            self::Stocks => 'Залишки',
            self::Customers => 'Клієнти',
            self::Statuses => 'Статуси замовлень',
        };
    }
}
