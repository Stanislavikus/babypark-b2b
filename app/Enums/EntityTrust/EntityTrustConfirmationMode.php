<?php

namespace App\Enums\EntityTrust;

enum EntityTrustConfirmationMode: string
{
    case SimpleVariant = 'simple_variant';
    case ConfigurableExistingParent = 'configurable_existing_parent';
}
