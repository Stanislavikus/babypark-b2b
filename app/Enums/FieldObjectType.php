<?php

namespace App\Enums;

enum FieldObjectType: string
{
    case Product = 'product';
    case ProductVariant = 'product_variant';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Товар',
            self::ProductVariant => 'Варіант',
            self::Customer => 'Клієнт',
        };
    }
}
