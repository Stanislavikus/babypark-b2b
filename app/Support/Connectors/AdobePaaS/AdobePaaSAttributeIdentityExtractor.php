<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;

final class AdobePaaSAttributeIdentityExtractor
{
    public function extract(#[\SensitiveParameter] mixed $raw): string
    {
        if (! $raw instanceof \stdClass) {
            throw ConnectorDiscoverySchemaValidationException::at(ConnectorDiscoverySchemaValidationReason::MalformedObject, '$');
        }
        if (! property_exists($raw, 'attribute_code')) {
            throw ConnectorDiscoverySchemaValidationException::at(ConnectorDiscoverySchemaValidationReason::MissingRequiredValue, 'attribute_code');
        }
        if (! is_string($raw->attribute_code)) {
            throw ConnectorDiscoverySchemaValidationException::at(ConnectorDiscoverySchemaValidationReason::InvalidType, 'attribute_code');
        }
        if ($raw->attribute_code === '') {
            throw ConnectorDiscoverySchemaValidationException::at(ConnectorDiscoverySchemaValidationReason::EmptyRequiredString, 'attribute_code');
        }
        if (! mb_check_encoding($raw->attribute_code, 'UTF-8')) {
            throw ConnectorDiscoverySchemaValidationException::at(ConnectorDiscoverySchemaValidationReason::InvalidUtf8, 'attribute_code');
        }

        return $raw->attribute_code;
    }
}
