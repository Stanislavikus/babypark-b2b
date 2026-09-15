<?php

namespace App\Support\Sync\Preview\Presentation;

use App\Enums\SyncPreviewFindingCode;

final class SyncPreviewFindingReferenceResolver
{
    /**
     * @param  array<string, mixed>  $finding
     */
    public function resolve(array $finding): SyncPreviewFindingReference
    {
        $codeValue = $finding['code'] ?? null;
        $code = is_string($codeValue) ? SyncPreviewFindingCode::tryFrom($codeValue) : null;
        $subject = $this->subjectValue($finding['subject'] ?? null);
        $context = $finding['context'] ?? [];
        $context = is_array($context) ? $context : [];

        if ($code === null) {
            return $this->emptyReference();
        }

        $contextBindingId = $this->identifierValue($context['field_binding_id'] ?? null);
        $internalOptionKey = $this->stringOrNull($context['internal_option_key'] ?? null);
        $externalFieldKey = $this->stringOrNull($context['external_field_key'] ?? null);
        $externalOptionValue = $this->stringOrNull($context['external_option_value'] ?? null);

        return match ($code) {
            SyncPreviewFindingCode::MissingRequiredFieldMapping => new SyncPreviewFindingReference(
                code: $code,
                fieldBindingId: null,
                variantId: null,
                productId: null,
                internalOptionKey: null,
                externalFieldKey: null,
                externalOptionValue: null,
                showsVariantContext: false,
            ),
            SyncPreviewFindingCode::MissingMappedProductValue => new SyncPreviewFindingReference(
                code: $code,
                fieldBindingId: $subject,
                variantId: null,
                productId: null,
                internalOptionKey: null,
                externalFieldKey: null,
                externalOptionValue: null,
                showsVariantContext: false,
            ),
            SyncPreviewFindingCode::MissingOptionMapping => new SyncPreviewFindingReference(
                code: $code,
                fieldBindingId: $subject,
                variantId: null,
                productId: null,
                internalOptionKey: $internalOptionKey,
                externalFieldKey: null,
                externalOptionValue: null,
                showsVariantContext: false,
            ),
            SyncPreviewFindingCode::ExternalOptionMissingOrStale => new SyncPreviewFindingReference(
                code: $code,
                fieldBindingId: $subject,
                variantId: null,
                productId: null,
                internalOptionKey: null,
                externalFieldKey: $externalFieldKey,
                externalOptionValue: $externalOptionValue,
                showsVariantContext: false,
            ),
            SyncPreviewFindingCode::MissingMappedVariantValue => new SyncPreviewFindingReference(
                code: $code,
                fieldBindingId: $contextBindingId,
                variantId: $subject,
                productId: null,
                internalOptionKey: null,
                externalFieldKey: null,
                externalOptionValue: null,
                showsVariantContext: true,
            ),
            SyncPreviewFindingCode::MissingSku,
            SyncPreviewFindingCode::PriceUnavailable,
            SyncPreviewFindingCode::PriceConfigurationError => new SyncPreviewFindingReference(
                code: $code,
                fieldBindingId: null,
                variantId: $subject,
                productId: null,
                internalOptionKey: null,
                externalFieldKey: null,
                externalOptionValue: null,
                showsVariantContext: true,
            ),
            SyncPreviewFindingCode::MissingName,
            SyncPreviewFindingCode::NoConfigurableDimension,
            SyncPreviewFindingCode::NoSellableVariant,
            SyncPreviewFindingCode::ConfigurableVariantsIncomplete,
            SyncPreviewFindingCode::DuplicateConfigurableCombination => new SyncPreviewFindingReference(
                code: $code,
                fieldBindingId: null,
                variantId: null,
                productId: $subject,
                internalOptionKey: null,
                externalFieldKey: null,
                externalOptionValue: null,
                showsVariantContext: false,
            ),
            SyncPreviewFindingCode::MappedFieldAbsentFromSelectedSet,
            SyncPreviewFindingCode::InvalidConfigurableAttribute => new SyncPreviewFindingReference(
                code: $code,
                fieldBindingId: $contextBindingId,
                variantId: null,
                productId: null,
                internalOptionKey: null,
                externalFieldKey: $subject,
                externalOptionValue: null,
                showsVariantContext: false,
            ),
            SyncPreviewFindingCode::AttributeSetUnconfigured => new SyncPreviewFindingReference(
                code: $code,
                fieldBindingId: null,
                variantId: null,
                productId: null,
                internalOptionKey: null,
                externalFieldKey: null,
                externalOptionValue: null,
                showsVariantContext: false,
            ),
            SyncPreviewFindingCode::AttributeSetInvalid => new SyncPreviewFindingReference(
                code: $code,
                fieldBindingId: null,
                variantId: null,
                productId: null,
                internalOptionKey: null,
                externalFieldKey: null,
                externalOptionValue: null,
                showsVariantContext: false,
            ),
        };
    }

    private function emptyReference(): SyncPreviewFindingReference
    {
        return new SyncPreviewFindingReference(
            code: null,
            fieldBindingId: null,
            variantId: null,
            productId: null,
            internalOptionKey: null,
            externalFieldKey: null,
            externalOptionValue: null,
            showsVariantContext: false,
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function identifierValue(mixed $value): ?string
    {
        return $this->subjectValue($value);
    }

    private function subjectValue(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }
}
