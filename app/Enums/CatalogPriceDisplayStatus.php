<?php

namespace App\Enums;

enum CatalogPriceDisplayStatus: string
{
    case Resolved = 'resolved';
    case Unavailable = 'unavailable';
    case ConfigurationError = 'configuration_error';
}
