<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Warehouse = 'warehouse';
    case Merchandiser = 'товарознавець';
    case Director = 'director';
    case Programmer = 'programmer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Адміністратор',
            self::Manager => 'Менеджер',
            self::Warehouse => 'Склад',
            self::Merchandiser => 'Товарознавець',
            self::Director => 'Директор',
            self::Programmer => 'Програміст',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
