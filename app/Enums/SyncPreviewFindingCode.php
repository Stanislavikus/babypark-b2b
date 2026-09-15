<?php

namespace App\Enums;

enum SyncPreviewFindingCode: string
{
    case MissingRequiredFieldMapping = 'missing_required_field_mapping';
    case MissingMappedProductValue = 'missing_mapped_product_value';
    case MissingMappedVariantValue = 'missing_mapped_variant_value';
    case MissingOptionMapping = 'missing_option_mapping';
    case MissingSku = 'missing_sku';
    case MissingName = 'missing_name';
    case PriceUnavailable = 'price_unavailable';
    case PriceConfigurationError = 'price_configuration_error';
    case AttributeSetUnconfigured = 'attribute_set_unconfigured';
    case AttributeSetInvalid = 'attribute_set_invalid';
    case MappedFieldAbsentFromSelectedSet = 'mapped_field_absent_from_selected_set';
    case InvalidConfigurableAttribute = 'invalid_configurable_attribute';
    case ExternalOptionMissingOrStale = 'external_option_missing_or_stale';
    case NoConfigurableDimension = 'no_configurable_dimension';
    case DuplicateConfigurableCombination = 'duplicate_configurable_combination';
    case NoSellableVariant = 'no_sellable_variant';
    case ConfigurableVariantsIncomplete = 'configurable_variants_incomplete';

    public function messageKey(): string
    {
        return 'sync.preview.findings.'.$this->value;
    }
}
