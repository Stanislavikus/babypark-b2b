<?php

namespace App\Enums;

enum SyncPreviewFindingCode: string
{
    case MissingRequiredFieldMapping = 'missing_required_field_mapping';
    case MissingMappedProductValue = 'missing_mapped_product_value';
    case MissingMappedVariantValue = 'missing_mapped_variant_value';
    case MissingOptionMapping = 'missing_option_mapping';
    case MissingVariantOptionValue = 'missing_variant_option_value';
    case MissingSku = 'missing_sku';
    case MissingName = 'missing_name';
    case PriceUnavailable = 'price_unavailable';
    case PriceConfigurationError = 'price_configuration_error';
    case AttributeSetUnconfigured = 'attribute_set_unconfigured';
    case ConfigurableVariantsIncomplete = 'configurable_variants_incomplete';

    public function messageKey(): string
    {
        return 'sync.preview.findings.'.$this->value;
    }
}
