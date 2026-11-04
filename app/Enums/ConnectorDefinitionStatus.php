<?php

namespace App\Enums;

enum ConnectorDefinitionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Deprecated = 'deprecated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Чернетка',
            self::Active => 'Активна',
            self::Deprecated => 'Застаріла',
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
