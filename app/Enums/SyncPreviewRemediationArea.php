<?php

namespace App\Enums;

enum SyncPreviewRemediationArea: string
{
    case ProductData = 'product_data';
    case VariantData = 'variant_data';
    case FieldMapping = 'field_mapping';
    case OptionMapping = 'option_mapping';
    case ConnectorSetup = 'connector_setup';
    case Pricing = 'pricing';
}
