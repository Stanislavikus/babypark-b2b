<?php

namespace App\Enums;

enum ConnectorDirection: string
{
    case Import = 'import';
    case Export = 'export';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Import => 'Імпорт',
            self::Export => 'Експорт',
            self::Both => 'Імпорт і експорт',
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
