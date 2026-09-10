<?php

namespace App\Enums;

enum ConnectorConnectionCheckTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case BeforeDiscovery = 'before_discovery';

    public function label(): string
    {
        return 'connectors.enums.connection_check_trigger.'.$this->value;
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
