<?php

namespace App\Enums;

enum AttributeStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активне',
            self::Archived => 'Архів',
        };
    }
}
