<?php

namespace App\Enums;

enum ConnectorAccountConnectionStatus: string
{
    case Untested = 'untested';
    case Connected = 'connected';
    case AttentionRequired = 'attention_required';
    case TemporarilyUnavailable = 'temporarily_unavailable';
    case Disabled = 'disabled';

    public function label(): string
    {
        return 'connectors.enums.account_connection_status.'.$this->value;
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
