<?php

namespace App\Enums;

enum AttributeValueLevel: string
{
    case Product = 'product';
    case Variant = 'variant';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Товар',
            self::Variant => 'Варіант',
            self::Both => 'Обидва',
        };
    }
}
