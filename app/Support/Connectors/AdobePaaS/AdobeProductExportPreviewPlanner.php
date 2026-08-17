<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\AttributeDataType;
use App\Enums\FieldObjectType;
use App\Enums\SyncPreviewFindingCode;
use App\Enums\SyncPreviewOutcome;
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

        $connectorConfig = $configurationSnapshot['connector_execution_configuration'] ?? [];
        $attributeSetId = null;

        if (! is_array($connectorConfig) || ! isset($connectorConfig['attribute_set_id'])) {
            $findings[] = new SyncPreviewFinding(SyncPreviewFindingCode::AttributeSetUnconfigured);
        } else {
            $rawAttributeSetId = $connectorConfig['attribute_set_id'];

            if (is_int($rawAttributeSetId) || (is_string($rawAttributeSetId) && ctype_digit($rawAttributeSetId))) {
                $attributeSetId = (int) $rawAttributeSetId;
            } else {
                $findings[] = new SyncPreviewFinding(SyncPreviewFindingCode::AttributeSetInvalid);
            }
        }

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
                $metadata,
            );

            $findings = array_merge($findings, $configurableResult['findings']);

            return new SyncPreviewPlanResult(
                outcome: $this->resolveOutcome($findings),
                findings: $findings,
                connectorPlan: $configurableResult['plan'],
            );
        }

        $simpleResult = $this->planSimplePath($aggregate, $skuBindingId);
        $findings = array_merge($findings, $simpleResult['findings']);

        return new SyncPreviewPlanResult(
            outcome: $this->resolveOutcome($findings),
            findings: $findings,
            connectorPlan: $simpleResult['plan'],
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $mappedBindings
     * @return array{findings: list<SyncPreviewFinding>, plan: ?AdobeProductExportPreviewPlan}
     */
    private function planSimplePath(
        ProductExecutionAggregate $aggregate,
        ?string $skuBindingId,
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

        $plan = new AdobeProductExportPreviewPlan([
            new AdobeProductExportPreviewPlanOperation(
                operation: 'simple_product',
                context: [
                    'product_id' => $aggregate->productId,
                    'variant_id' => $variantSlice->variantId,
                    'sku' => is_string($sku) ? $sku : (is_scalar($sku) ? (string) $sku : null),
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

        $operations = [
            new AdobeProductExportPreviewPlanOperation(
                operation: 'configurable_parent',
                context: ['product_id' => $aggregate->productId],
            ),
        ];

        foreach ($dimensions as $dimension) {
            $operations[] = new AdobeProductExportPreviewPlanOperation(
                operation: 'configurable_attribute',
                context: [
                    'external_field_key' => $dimension['external_field_key'],
                    'field_binding_id' => $dimension['field_binding_id'],
                ],
            );

            $seenInternalKeys = [];

            foreach ($dimension['option_mappings'] as $optionMapping) {
                $internalKey = $optionMapping['internal_option_key'] ?? null;
                $externalValue = $optionMapping['external_option_value'] ?? null;

                if (! is_string($internalKey) || ! is_string($externalValue)) {
                    continue;
                }

                if (isset($seenInternalKeys[$internalKey])) {
                    continue;
                }

                $seenInternalKeys[$internalKey] = true;

                $operations[] = new AdobeProductExportPreviewPlanOperation(
                    operation: 'option_assignment',
                    context: [
                        'field_binding_id' => $dimension['field_binding_id'],
                        'external_field_key' => $dimension['external_field_key'],
                        'internal_option_key' => $internalKey,
                        'external_option_value' => $externalValue,
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

            $isQualifying = false;

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

                $isQualifying = true;
                break;
            }

            if (! $isQualifying) {
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
