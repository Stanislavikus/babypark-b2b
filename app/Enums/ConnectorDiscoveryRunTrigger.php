<?php

namespace App\Enums;

enum ConnectorDiscoveryRunTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case AfterConnectionCheck = 'after_connection_check';

    public function label(): string
    {
        return 'connectors.enums.discovery_run_trigger.'.$this->value;
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
