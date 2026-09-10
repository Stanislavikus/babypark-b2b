<?php

namespace App\Enums;

enum ConnectorSchemaAcquisitionMode: string
{
    case RemoteStatic = 'remote_static';
    case LiveFetch = 'live_fetch';
    case BundledFile = 'bundled_file';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::RemoteStatic => 'Віддалене статичне',
            self::LiveFetch => 'Живий запит',
            self::BundledFile => 'Вбудований файл',
            self::Manual => 'Ручне',
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
