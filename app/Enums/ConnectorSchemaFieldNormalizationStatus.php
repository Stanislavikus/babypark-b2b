<?php

namespace App\Enums;

enum ConnectorSchemaFieldNormalizationStatus: string
{
    case Normalized = 'normalized';
    case Unclassified = 'unclassified';
}
