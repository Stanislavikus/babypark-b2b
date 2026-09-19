<?php

namespace App\Enums;

enum ConnectorSchemaScope: string
{
    case Global = 'global';
    case Account = 'account';

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Глобальна',
            self::Account => 'Обліковий запис',
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
