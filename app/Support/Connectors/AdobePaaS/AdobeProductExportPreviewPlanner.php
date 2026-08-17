<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\AttributeDataType;
use App\Enums\FieldObjectType;
use App\Enums\SyncPreviewFindingCode;
use App\Enums\SyncPreviewOutcome;
use App\Services\Pricing\ResolvedPrice;
use App\Support\Sync\Preview\MappedFieldValue;
use App\Support\Sync\Preview\ProductExecutionAggregate;
use App\Support\Sync\Preview\ProductVariantExecutionSlice;
use App\Support\Sync\Preview\SyncPreviewFinding;
use App\Support\Sync\Preview\SyncPreviewPlanResult;

final class AdobeProductExportPreviewPlanner
{
    /**
     * @param  array<string, mixed>  $configurationSnapshot
     */
    public function plan(
        ProductExecutionAggregate $aggregate,
        array $configurationSnapshot,
        ?AdobeProductExportExecutionMetadata $metadata = null,
    ): SyncPreviewPlanResult {
        /** @var list<SyncPreviewFinding> $findings */
        $findings = [];

        $attributeSetId = $this->resolveAttributeSetId($configurationSnapshot, $findings);

        if ($metadata !== null && $attributeSetId !== null) {
            $attributeSetExists = false;

            foreach ($metadata->attributeSets as $attributeSet) {
                if (($attributeSet['attribute_set_id'] ?? null) === $attributeSetId) {
                    $attributeSetExists = true;
                    break;
                }
            }

            if (! $attributeSetExists) {
                $findings[] = new SyncPreviewFinding(
                    SyncPreviewFindingCode::AttributeSetInvalid,
                    subject: (string) $attributeSetId,
                );
            }
        }

        /** @var list<array<string, mixed>> $fieldMappings */
        $fieldMappings = $configurationSnapshot['field_mappings'] ?? [];
        $mappedBindings = $this->indexedFieldMappings($fieldMappings);

        $nameBindingId = $this->findBindingIdByExternalKey($fieldMappings, 'name');
        $skuBindingId = $this->findBindingIdByExternalKey($fieldMappings, 'sku');

        if ($nameBindingId === null) {
            $findings[] = new SyncPreviewFinding(
                SyncPreviewFindingCode::MissingRequiredFieldMapping,
                subject: 'name',
            );
        }

        if ($skuBindingId === null) {
            $findings[] = new SyncPreviewFinding(
                SyncPreviewFindingCode::MissingRequiredFieldMapping,
                subject: 'sku',
            );
        }

        if ($nameBindingId !== null) {
            $nameValue = $this->mappedScalarValue($aggregate->productValues[$nameBindingId] ?? null);

            if ($nameValue === null || $nameValue === '') {
                $findings[] = new SyncPreviewFinding(
                    SyncPreviewFindingCode::MissingName,
                    subject: $aggregate->productId,
                );
            } elseif (! isset($aggregate->productValues[$nameBindingId])) {
                $findings[] = new SyncPreviewFinding(
                    SyncPreviewFindingCode::MissingMappedProductValue,
                    subject: $nameBindingId,
                );
            }
        }

        if (! $aggregate->hasSellableVariants()) {
            $findings[] = new SyncPreviewFinding(
                SyncPreviewFindingCode::NoSellableVariant,
                subject: $aggregate->productId,
            );

            return new SyncPreviewPlanResult(
                outcome: $this->resolveOutcome($findings),
                findings: $findings,
            );
        }

        if ($aggregate->hasMultipleSellableVariants()) {
            $configurableResult = $this->planConfigurablePath(
                $aggregate,
                $mappedBindings,
                $skuBindingId,
                $attributeSetId,
                $metadata,
            );

            $findings = array_merge($findings, $configurableResult['findings']);

            return new SyncPreviewPlanResult(
                outcome: $this->resolveOutcome($findings),
                findings: $findings,
                connectorPlan: $configurableResult['plan'],
            );
        }

        $simpleResult = $this->planSimplePath(
            $aggregate,
            $skuBindingId,
            $attributeSetId,
            $nameBindingId,
        );
        $findings = array_merge($findings, $simpleResult['findings']);

        return new SyncPreviewPlanResult(
            outcome: $this->resolveOutcome($findings),
            findings: $findings,
            connectorPlan: $simpleResult['plan'],
        );
    }

    /**
     * @param  array<string, mixed>  $configurationSnapshot
     * @param  list<SyncPreviewFinding>  $findings
     */
    private function resolveAttributeSetId(array $configurationSnapshot, array &$findings): ?int
    {
        $connectorConfig = $configurationSnapshot['connector_execution_configuration'] ?? [];

        if (! is_array($connectorConfig) || ! isset($connectorConfig['attribute_set_id'])) {
            $findings[] = new SyncPreviewFinding(SyncPreviewFindingCode::AttributeSetUnconfigured);

            return null;
        }

        $rawAttributeSetId = $connectorConfig['attribute_set_id'];

        if (is_int($rawAttributeSetId) || (is_string($rawAttributeSetId) && ctype_digit($rawAttributeSetId))) {
            return (int) $rawAttributeSetId;
        }

        $findings[] = new SyncPreviewFinding(SyncPreviewFindingCode::AttributeSetInvalid);

        return null;
    }

    /**
     * @return array{findings: list<SyncPreviewFinding>, plan: ?AdobeProductExportPreviewPlan}
     */
    private function planSimplePath(
        ProductExecutionAggregate $aggregate,
        ?string $skuBindingId,
        ?int $attributeSetId,
        ?string $nameBindingId,
    ): array {
        $findings = [];
        $variantSlice = $aggregate->variants[0] ?? null;

        if ($variantSlice === null) {
            return ['findings' => $findings, 'plan' => null];
        }

        $findings = array_merge(
            $findings,
            $this->evaluateVariantCommon($variantSlice, $skuBindingId),
        );

        if ($this->hasBlockingFinding($findings)) {
            return ['findings' => $findings, 'plan' => null];
        }

        $sku = $skuBindingId !== null
            ? $this->mappedScalarValue($variantSlice->values[$skuBindingId] ?? null)
            : null;

        $name = $nameBindingId !== null
            ? $this->mappedScalarValue($aggregate->productValues[$nameBindingId] ?? null)
            : null;

        $plan = new AdobeProductExportPreviewPlan([
            new AdobeProductExportPreviewPlanOperation(
                operation: 'simple_product',
                context: [
                    'product_id' => $aggregate->productId,
                    'variant_id' => $variantSlice->variantId,
                    'sku' => is_string($sku) ? $sku : (is_scalar($sku) ? (string) $sku : null),
                    'attribute_set_id' => $attributeSetId,
                    'name' => is_string($name) ? $name : (is_scalar($name) ? (string) $name : null),
                    'product_type' => 'simple',
                    'visibility' => 'not_visible',
                    'status' => 1,
                    'mapped_product_values' => $this->serializeMappedValues($aggregate->productValues),
                    'mapped_variant_values' => $this->serializeMappedValues($variantSlice->values),
                    'resolved_price' => $this->serializeResolvedPrice($variantSlice->resolvedPrice),
                ],
            ),
        ]);

        return ['findings' => $findings, 'plan' => $plan];
    }

    /**
     * @param  array<string, array<string, mixed>>  $mappedBindings
     * @return array{findings: list<SyncPreviewFinding>, plan: ?AdobeProductExportPreviewPlan}
     */
    private function planConfigurablePath(
        ProductExecutionAggregate $aggregate,
        array $mappedBindings,
        ?string $skuBindingId,
        ?int $attributeSetId,
        ?AdobeProductExportExecutionMetadata $metadata,
    ): array {
        $findings = [];
        $dimensions = $this->qualifyingConfigurableDimensions($aggregate, $mappedBindings);

        if ($dimensions === []) {
            $findings[] = new SyncPreviewFinding(
                SyncPreviewFindingCode::NoConfigurableDimension,
                subject: $aggregate->productId,
            );

            return ['findings' => $findings, 'plan' => null];
        }

        foreach ($dimensions as $dimension) {
            $externalKey = $dimension['external_field_key'];
            $bindingId = $dimension['field_binding_id'];

            if ($metadata !== null && $metadata->attributeByCode($externalKey) === null) {
                $findings[] = new SyncPreviewFinding(
                    SyncPreviewFindingCode::MappedFieldAbsentFromSelectedSet,
                    subject: $externalKey,
                    context: ['field_binding_id' => $bindingId],
                );
            }

            if ($metadata !== null
                && $metadata->attributeByCode($externalKey) !== null
                && ! $metadata->isConfigurableCompatible($externalKey)
            ) {
                $findings[] = new SyncPreviewFinding(
                    SyncPreviewFindingCode::InvalidConfigurableAttribute,
                    subject: $externalKey,
                    context: ['field_binding_id' => $bindingId],
                );
            }
        }

        $completeVariantCount = 0;
        /** @var array<string, int> $combinationCounts */
        $combinationCounts = [];
        /** @var array<string, array<string, true>> $usedInternalOptionKeysByBinding */
        $usedInternalOptionKeysByBinding = [];

        foreach ($aggregate->variants as $variantSlice) {
            $externalCombination = [];
            $variantComplete = true;

            foreach ($dimensions as $dimension) {
                $bindingId = $dimension['field_binding_id'];
                $externalKey = $dimension['external_field_key'];
                $optionMappings = $dimension['option_mappings'];
                $dimensionValue = $this->mappedScalarValue($variantSlice->values[$bindingId] ?? null);

                if ($dimensionValue === null || $dimensionValue === '') {
                    $findings[] = new SyncPreviewFinding(
                        SyncPreviewFindingCode::MissingMappedVariantValue,
                        subject: $variantSlice->variantId,
                        context: ['field_binding_id' => $bindingId],
                    );
                    $variantComplete = false;

                    continue;
                }

                $internalKey = is_string($dimensionValue) ? $dimensionValue : (string) $dimensionValue;
                $externalOptionValue = $this->resolveExternalOptionValue($optionMappings, $internalKey);

                if ($externalOptionValue === null) {
                    $findings[] = new SyncPreviewFinding(
                        SyncPreviewFindingCode::MissingOptionMapping,
                        subject: $bindingId,
                        context: ['internal_option_key' => $internalKey],
                    );
                    $variantComplete = false;

                    continue;
                }

                if ($metadata !== null && ! $metadata->optionExists($externalKey, $externalOptionValue)) {
                    $findings[] = new SyncPreviewFinding(
                        SyncPreviewFindingCode::ExternalOptionMissingOrStale,
                        subject: $bindingId,
                        context: [
                            'external_field_key' => $externalKey,
                            'external_option_value' => $externalOptionValue,
                        ],
                    );
                }

                $usedInternalOptionKeysByBinding[$bindingId][$internalKey] = true;
                $externalCombination[$externalKey] = $externalOptionValue;
            }

            $findings = array_merge(
                $findings,
                $this->evaluateVariantCommon($variantSlice, $skuBindingId),
            );

            if ($variantComplete && $externalCombination !== []) {
                $completeVariantCount++;
                $parts = [];

                foreach ($externalCombination as $key => $value) {
                    $parts[] = $key.'='.$value;
                }

                $combinationKey = implode('|', $parts);
                $combinationCounts[$combinationKey] = ($combinationCounts[$combinationKey] ?? 0) + 1;
            }
        }

        if ($this->hasFindingCode($findings, SyncPreviewFindingCode::MissingMappedVariantValue)) {
            $findings[] = new SyncPreviewFinding(
                SyncPreviewFindingCode::ConfigurableVariantsIncomplete,
                subject: $aggregate->productId,
            );
        }

        if ($completeVariantCount < count($aggregate->variants)) {
            return ['findings' => $findings, 'plan' => null];
        }

        foreach ($combinationCounts as $combinationKey => $count) {
            if ($count > 1) {
                $findings[] = new SyncPreviewFinding(
                    SyncPreviewFindingCode::DuplicateConfigurableCombination,
                    subject: $aggregate->productId,
                    context: ['combination' => $combinationKey],
                );
            }
        }

        if ($this->hasBlockingFinding($findings)) {
            return ['findings' => $findings, 'plan' => null];
        }

        $nameBindingId = $this->findBindingIdByExternalKeyFromIndexed($mappedBindings, 'name');
        $name = $nameBindingId !== null
            ? $this->mappedScalarValue($aggregate->productValues[$nameBindingId] ?? null)
            : null;

        $operations = [
            new AdobeProductExportPreviewPlanOperation(
                operation: 'configurable_parent',
                context: [
                    'product_id' => $aggregate->productId,
                    'attribute_set_id' => $attributeSetId,
                    'name' => is_string($name) ? $name : (is_scalar($name) ? (string) $name : null),
                    'product_type' => 'configurable',
                    'visibility' => 'catalog_search',
                    'status' => 1,
                    'mapped_product_values' => $this->serializeMappedValues($aggregate->productValues),
                ],
            ),
        ];

        foreach ($dimensions as $dimension) {
            $externalKey = $dimension['external_field_key'];
            $bindingId = $dimension['field_binding_id'];
            $attributeMetadata = $metadata?->attributeByCode($externalKey);

            $operations[] = new AdobeProductExportPreviewPlanOperation(
                operation: 'configurable_attribute',
                context: [
                    'external_field_key' => $externalKey,
                    'field_binding_id' => $bindingId,
                    'attribute_id' => $attributeMetadata?->attributeId,
                ],
            );

            $usedInternalKeys = $usedInternalOptionKeysByBinding[$bindingId] ?? [];

            foreach ($dimension['option_mappings'] as $optionMapping) {
                $internalKey = $optionMapping['internal_option_key'] ?? null;
                $externalValue = $optionMapping['external_option_value'] ?? null;

                if (! is_string($internalKey) || ! is_string($externalValue)) {
                    continue;
                }

                if (! isset($usedInternalKeys[$internalKey])) {
                    continue;
                }

                $operations[] = new AdobeProductExportPreviewPlanOperation(
                    operation: 'option_assignment',
                    context: [
                        'field_binding_id' => $bindingId,
                        'external_field_key' => $externalKey,
                        'attribute_id' => $attributeMetadata?->attributeId,
                        'internal_option_key' => $internalKey,
                        'external_option_value' => $externalValue,
                        'value_index' => $externalValue,
                    ],
                );
            }
        }

        foreach ($aggregate->variants as $variantSlice) {
            $sku = $skuBindingId !== null
                ? $this->mappedScalarValue($variantSlice->values[$skuBindingId] ?? null)
                : null;

            $operations[] = new AdobeProductExportPreviewPlanOperation(
                operation: 'simple_child',
                context: [
                    'product_id' => $aggregate->productId,
                    'variant_id' => $variantSlice->variantId,
                    'sku' => is_string($sku) ? $sku : (is_scalar($sku) ? (string) $sku : null),
                    'attribute_set_id' => $attributeSetId,
                    'product_type' => 'simple',
                    'visibility' => 'not_visible',
                    'status' => 1,
                    'mapped_variant_values' => $this->serializeMappedValues($variantSlice->values),
                    'resolved_price' => $this->serializeResolvedPrice($variantSlice->resolvedPrice),
                ],
            );

            $operations[] = new AdobeProductExportPreviewPlanOperation(
                operation: 'child_link',
                context: [
                    'product_id' => $aggregate->productId,
                    'variant_id' => $variantSlice->variantId,
                ],
            );
        }

        return [
            'findings' => $findings,
            'plan' => new AdobeProductExportPreviewPlan($operations),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $mappedBindings
     * @return list<array{
     *     field_binding_id: string,
     *     external_field_key: string,
     *     option_mappings: list<array<string, mixed>>
     * }>
     */
    private function qualifyingConfigurableDimensions(
        ProductExecutionAggregate $aggregate,
        array $mappedBindings,
    ): array {
        $dimensions = [];

        foreach ($mappedBindings as $bindingId => $mapping) {
            $externalFieldKey = $mapping['external_field_key'] ?? null;

            if (! is_string($externalFieldKey) || $externalFieldKey === '') {
                continue;
            }

            /** @var array<string, true> $distinctValues */
            $distinctValues = [];

            foreach ($aggregate->variants as $variantSlice) {
                $mapped = $variantSlice->values[$bindingId] ?? null;

                if ($mapped === null) {
                    continue;
                }

                if ($mapped->objectType !== FieldObjectType::ProductVariant) {
                    continue;
                }

                if ($mapped->dataType !== AttributeDataType::Select) {
                    continue;
                }

                if ($mapped->isMultiValue) {
                    continue;
                }

                $value = $mapped->value;

                if ($value === null || $value === '') {
                    continue;
                }

                $distinctValues[is_string($value) ? $value : (string) $value] = true;
            }

            if (count($distinctValues) < 2) {
                continue;
            }

            $optionMappings = $mapping['option_mappings'] ?? [];

            $dimensions[] = [
                'field_binding_id' => $bindingId,
                'external_field_key' => $externalFieldKey,
                'option_mappings' => is_array($optionMappings) ? $optionMappings : [],
            ];
        }

        usort(
            $dimensions,
            static fn (array $left, array $right): int => strcmp($left['field_binding_id'], $right['field_binding_id']),
        );

        return $dimensions;
    }

    /**
     * @param  list<array<string, mixed>>  $optionMappings
     */
    private function resolveExternalOptionValue(array $optionMappings, string $internalKey): ?string
    {
        foreach ($optionMappings as $optionMapping) {
            if (($optionMapping['internal_option_key'] ?? null) === $internalKey) {
                $externalOptionValue = $optionMapping['external_option_value'] ?? null;

                return is_string($externalOptionValue) ? $externalOptionValue : null;
            }
        }

        return null;
    }

    /**
     * @return list<SyncPreviewFinding>
     */
    private function evaluateVariantCommon(
        ProductVariantExecutionSlice $variantSlice,
        ?string $skuBindingId,
    ): array {
        $findings = [];

        if ($skuBindingId !== null) {
            $sku = $this->mappedScalarValue($variantSlice->values[$skuBindingId] ?? null);

            if ($sku === null || $sku === '') {
                $findings[] = new SyncPreviewFinding(
                    SyncPreviewFindingCode::MissingSku,
                    subject: $variantSlice->variantId,
                );
            }
        }

        if ($variantSlice->priceResolutionStatus === 'unavailable') {
            $findings[] = new SyncPreviewFinding(
                SyncPreviewFindingCode::PriceUnavailable,
                subject: $variantSlice->variantId,
            );
        } elseif ($variantSlice->priceResolutionStatus === 'configuration_error') {
            $findings[] = new SyncPreviewFinding(
                SyncPreviewFindingCode::PriceConfigurationError,
                subject: $variantSlice->variantId,
            );
        }

        return $findings;
    }

    /**
     * @param  array<string, MappedFieldValue>  $values
     * @return array<string, mixed>
     */
    private function serializeMappedValues(array $values): array
    {
        $serialized = [];

        foreach ($values as $bindingId => $mapped) {
            $serialized[$bindingId] = [
                'internal_code' => $mapped->internalCode,
                'value' => $mapped->value,
            ];
        }

        return $serialized;
    }

    private function serializeResolvedPrice(?ResolvedPrice $resolvedPrice): ?array
    {
        if ($resolvedPrice === null) {
            return null;
        }

        return [
            'effective_net_price' => $resolvedPrice->effectiveNetPrice,
            'gross_price' => $resolvedPrice->grossPrice,
            'currency' => $resolvedPrice->currency,
            'vat_rate' => $resolvedPrice->vatRate,
            'source' => $resolvedPrice->source,
        ];
    }

    private function mappedScalarValue(?MappedFieldValue $mapped): mixed
    {
        return $mapped?->value;
    }

    /**
     * @param  list<array<string, mixed>>  $fieldMappings
     * @return array<string, array<string, mixed>>
     */
    private function indexedFieldMappings(array $fieldMappings): array
    {
        $indexed = [];

        foreach ($fieldMappings as $mapping) {
            $bindingId = $mapping['field_binding_id'] ?? null;

            if (! is_string($bindingId)) {
                continue;
            }

            $indexed[$bindingId] = $mapping;
        }

        return $indexed;
    }

    /**
     * @param  list<array<string, mixed>>  $fieldMappings
     */
    private function findBindingIdByExternalKey(array $fieldMappings, string $externalFieldKey): ?string
    {
        foreach ($fieldMappings as $mapping) {
            if (($mapping['external_field_key'] ?? null) === $externalFieldKey) {
                $bindingId = $mapping['field_binding_id'] ?? null;

                return is_string($bindingId) ? $bindingId : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $mappedBindings
     */
    private function findBindingIdByExternalKeyFromIndexed(array $mappedBindings, string $externalFieldKey): ?string
    {
        foreach ($mappedBindings as $bindingId => $mapping) {
            if (($mapping['external_field_key'] ?? null) === $externalFieldKey) {
                return $bindingId;
            }
        }

        return null;
    }

    /**
     * @param  list<SyncPreviewFinding>  $findings
     */
    private function resolveOutcome(array $findings): SyncPreviewOutcome
    {
        if ($this->hasBlockingFinding($findings)) {
            return SyncPreviewOutcome::Blocked;
        }

        if ($findings !== []) {
            return SyncPreviewOutcome::Warning;
        }

        return SyncPreviewOutcome::Ready;
    }

    /**
     * @param  list<SyncPreviewFinding>  $findings
     */
    private function hasBlockingFinding(array $findings): bool
    {
        $blockedCodes = [
            SyncPreviewFindingCode::MissingRequiredFieldMapping,
            SyncPreviewFindingCode::MissingName,
            SyncPreviewFindingCode::MissingSku,
            SyncPreviewFindingCode::MissingMappedVariantValue,
            SyncPreviewFindingCode::MissingOptionMapping,
            SyncPreviewFindingCode::ConfigurableVariantsIncomplete,
            SyncPreviewFindingCode::AttributeSetUnconfigured,
            SyncPreviewFindingCode::AttributeSetInvalid,
            SyncPreviewFindingCode::MappedFieldAbsentFromSelectedSet,
            SyncPreviewFindingCode::InvalidConfigurableAttribute,
            SyncPreviewFindingCode::ExternalOptionMissingOrStale,
            SyncPreviewFindingCode::NoConfigurableDimension,
            SyncPreviewFindingCode::DuplicateConfigurableCombination,
            SyncPreviewFindingCode::NoSellableVariant,
            SyncPreviewFindingCode::PriceUnavailable,
            SyncPreviewFindingCode::PriceConfigurationError,
        ];

        foreach ($findings as $finding) {
            if (in_array($finding->code, $blockedCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<SyncPreviewFinding>  $findings
     */
    private function hasFindingCode(array $findings, SyncPreviewFindingCode $code): bool
    {
        foreach ($findings as $finding) {
            if ($finding->code === $code) {
                return true;
            }
        }

        return false;
    }
}
