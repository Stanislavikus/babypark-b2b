<?php

namespace App\Support\Connectors\AdobePaaS;

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

        if (! is_array($connectorConfig) || ! isset($connectorConfig['attribute_set_id'])) {
            $findings[] = new SyncPreviewFinding(SyncPreviewFindingCode::AttributeSetUnconfigured);
        }

        /** @var list<array<string, mixed>> $fieldMappings */
        $fieldMappings = $configurationSnapshot['field_mappings'] ?? [];
        $mappedBindings = $this->indexedFieldMappings($fieldMappings);

        $nameBindingId = $this->findBindingIdByExternalKey($fieldMappings, 'name');
        $skuBindingId = $this->findBindingIdByExternalKey($fieldMappings, 'sku');
        $colorBindingId = $this->findBindingIdByExternalKey($fieldMappings, 'color');

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

        if ($aggregate->hasMultipleSellableVariants()) {
            $findings = array_merge(
                $findings,
                $this->planConfigurablePath($aggregate, $mappedBindings, $colorBindingId, $skuBindingId, $metadata),
            );
        } else {
            $findings = array_merge(
                $findings,
                $this->planSimplePath($aggregate, $skuBindingId),
            );
        }

        return new SyncPreviewPlanResult(
            outcome: $this->resolveOutcome($findings),
            findings: $findings,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $mappedBindings
     * @return list<SyncPreviewFinding>
     */
    private function planConfigurablePath(
        ProductExecutionAggregate $aggregate,
        array $mappedBindings,
        ?string $colorBindingId,
        ?string $skuBindingId,
        ?AdobeProductExportExecutionMetadata $metadata,
    ): array {
        $findings = [];

        if ($colorBindingId === null) {
            $findings[] = new SyncPreviewFinding(
                SyncPreviewFindingCode::MissingRequiredFieldMapping,
                subject: 'color',
            );

            return $findings;
        }

        $colorExternalKey = $mappedBindings[$colorBindingId]['external_field_key'] ?? 'color';
        $colorExternalKey = is_string($colorExternalKey) ? $colorExternalKey : 'color';

        if ($metadata !== null && ! $metadata->isConfigurableCompatible($colorExternalKey)) {
            $findings[] = new SyncPreviewFinding(
                SyncPreviewFindingCode::MissingRequiredFieldMapping,
                subject: 'color',
            );
        }

        $colorMapping = $mappedBindings[$colorBindingId] ?? null;
        $optionMappings = is_array($colorMapping['option_mappings'] ?? null) ? $colorMapping['option_mappings'] : [];

        foreach ($aggregate->variants as $variantSlice) {
            $colorValue = $this->mappedScalarValue($variantSlice->values[$colorBindingId] ?? null);

            if ($colorValue === null || $colorValue === '') {
                $findings[] = new SyncPreviewFinding(
                    SyncPreviewFindingCode::MissingMappedVariantValue,
                    subject: $variantSlice->variantId,
                    context: ['field_binding_id' => $colorBindingId],
                );

                continue;
            }

            $internalKey = is_string($colorValue) ? $colorValue : (string) $colorValue;
            $hasMapping = false;
            $externalOptionValue = null;

            foreach ($optionMappings as $optionMapping) {
                if (($optionMapping['internal_option_key'] ?? null) === $internalKey) {
                    $hasMapping = true;
                    $externalOptionValue = $optionMapping['external_option_value'] ?? null;
                    break;
                }
            }

            if (! $hasMapping) {
                $findings[] = new SyncPreviewFinding(
                    SyncPreviewFindingCode::MissingOptionMapping,
                    subject: $colorBindingId,
                    context: ['internal_option_key' => $internalKey],
                );
            }

            if ($metadata !== null && $hasMapping && is_string($externalOptionValue) && ! $metadata->optionExists($colorExternalKey, $externalOptionValue)) {
                $findings[] = new SyncPreviewFinding(
                    SyncPreviewFindingCode::MissingOptionMapping,
                    subject: $colorBindingId,
                    context: ['external_option_value' => $externalOptionValue],
                );
            }

            $findings = array_merge(
                $findings,
                $this->evaluateVariantCommon($variantSlice, $skuBindingId),
            );
        }

        if ($this->hasBlockingFinding($findings, SyncPreviewFindingCode::MissingMappedVariantValue)) {
            $findings[] = new SyncPreviewFinding(
                SyncPreviewFindingCode::ConfigurableVariantsIncomplete,
                subject: $aggregate->productId,
            );
        }

        return $findings;
    }

    /**
     * @return list<SyncPreviewFinding>
     */
    private function planSimplePath(
        ProductExecutionAggregate $aggregate,
        ?string $skuBindingId,
    ): array {
        $findings = [];

        if ($skuBindingId === null) {
            return $findings;
        }

        $sku = $this->resolveSkuValue($aggregate, $skuBindingId);

        if ($sku === null || $sku === '') {
            $findings[] = new SyncPreviewFinding(
                SyncPreviewFindingCode::MissingSku,
                subject: $aggregate->productId,
            );
        }

        return $findings;
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

    private function resolveSkuValue(ProductExecutionAggregate $aggregate, string $skuBindingId): ?string
    {
        if ($aggregate->hasSellableVariants()) {
            $firstVariant = $aggregate->variants[0] ?? null;

            if ($firstVariant !== null) {
                $value = $this->mappedScalarValue($firstVariant->values[$skuBindingId] ?? null);

                return is_string($value) ? $value : (is_scalar($value) ? (string) $value : null);
            }
        }

        $value = $this->mappedScalarValue($aggregate->productValues[$skuBindingId] ?? null);

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : null);
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
        $blockedCodes = [
            SyncPreviewFindingCode::MissingRequiredFieldMapping,
            SyncPreviewFindingCode::MissingName,
            SyncPreviewFindingCode::MissingSku,
            SyncPreviewFindingCode::MissingVariantOptionValue,
            SyncPreviewFindingCode::ConfigurableVariantsIncomplete,
            SyncPreviewFindingCode::AttributeSetUnconfigured,
            SyncPreviewFindingCode::PriceConfigurationError,
        ];

        foreach ($findings as $finding) {
            if (in_array($finding->code, $blockedCodes, true)) {
                return SyncPreviewOutcome::Blocked;
            }
        }

        if ($findings !== []) {
            return SyncPreviewOutcome::Warning;
        }

        return SyncPreviewOutcome::Ready;
    }

    /**
     * @param  list<SyncPreviewFinding>  $findings
     */
    private function hasBlockingFinding(array $findings, SyncPreviewFindingCode $code): bool
    {
        foreach ($findings as $finding) {
            if ($finding->code === $code) {
                return true;
            }
        }

        return false;
    }
}
