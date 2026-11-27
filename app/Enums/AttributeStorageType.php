<?php

namespace App\Enums;

enum AttributeStorageType: string
{
    case Column = 'column';
    case Relation = 'relation';
    case Dynamic = 'dynamic';

    public function label(): string
    {
        return match ($this) {
            self::Column => 'Колонка',
            self::Relation => 'Зв\'язок',
            self::Dynamic => 'Динамічне',
        };
    }
}
