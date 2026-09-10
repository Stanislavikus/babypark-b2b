<?php

namespace App\Enums;

enum ConnectorErrorCause: string
{
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Configuration = 'configuration';
    case RateLimit = 'rate_limit';
    case VendorUnavailable = 'vendor_unavailable';
    case Network = 'network';
    case SchemaValidation = 'schema_validation';
    case DataValidation = 'data_validation';
    case Unknown = 'unknown';

    public function label(): string
    {
        return 'connectors.enums.error_cause.'.$this->value;
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
