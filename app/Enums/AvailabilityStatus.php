<?php

namespace App\Enums;

enum AvailabilityStatus: string
{
    case InStock = 'in_stock';
    case LowStock = 'low_stock';
    case OutOfStock = 'out_of_stock';
    case PreOrder = 'pre_order';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'В наявності',
            self::LowStock => 'Закінчується',
            self::OutOfStock => 'Немає в наявності',
            self::PreOrder => 'Передзамовлення',
        };
    }
}
