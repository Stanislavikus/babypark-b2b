<?php

namespace App\Enums;

enum ProductLifecycleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Чернетка',
            self::Active => 'Активний',
            self::Archived => 'Архівний',
        };
    }

    public function compatibilityIsActive(): bool
    {
        return $this === self::Active;
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
