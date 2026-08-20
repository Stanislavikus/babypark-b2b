<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticOperation;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;

final class AdobeProductDesiredStateCompiler
{
    /** @var list<string> */
    private const CONNECTOR_OWNED_EXTERNAL_KEYS = [
        'sku',
        'name',
        'status',
        'visibility',
        'price',
        'type_id',
        'attribute_set_id',
    ];

    /**
     * @throws AdobeProductCommandCompilationException
     */
    public function compileFromSemanticResult(
        AdobeProductExportSemanticResult $semanticResult,
    ): AdobeProductDesiredState {
        if ($semanticResult->hasBlockingFindings()) {
            throw AdobeProductCommandCompilationException::blockingSemanticFindings();
        }

        $operation = $this->extractSimpleProductOperation($semanticResult);

        return $this->compileFromOperation($operation);
    }

    /**
     * @throws AdobeProductCommandCompilationException
     */
    public function compileSimpleChildFromSemanticResult(
        AdobeProductExportSemanticResult $semanticResult,
        string $variantId,
    ): AdobeProductDesiredState {
        if ($semanticResult->hasBlockingFindings()) {
            throw AdobeProductCommandCompilationException::blockingSemanticFindings();
        }

        $operation = $this->extractSimpleChildOperation($semanticResult, $variantId);

        return $this->compileFromSimpleChildOperation($operation);
    }

    /**
     * @throws AdobeProductCommandCompilationException
     */
    private function compileFromOperation(
        AdobeProductExportSemanticOperation $operation,
    ): AdobeProductDesiredState {
        if ($operation->operation !== 'simple_product') {
            throw AdobeProductCommandCompilationException::unsupportedOperation($operation->operation);
        }

        $context = $operation->context;

        $variantId = $context['variant_id'] ?? null;
        $sku = $context['sku'] ?? null;
        $name = $context['name'] ?? null;
        $attributeSetId = $context['attribute_set_id'] ?? null;
        $status = $context['status'] ?? null;
        $visibilityNumeric = $context['visibility_numeric'] ?? null;
        $resolvedPrice = $context['resolved_price'] ?? null;

        if ((! is_string($variantId) && ! is_int($variantId)) || (string) $variantId === '') {
            throw AdobeProductCommandCompilationException::missingField('variant_id');
        }

        $variantId = (string) $variantId;

        if (! is_string($sku) || $sku === '') {
            throw AdobeProductCommandCompilationException::missingField('sku');
        }

        if (! is_string($name) || $name === '') {
            throw AdobeProductCommandCompilationException::missingField('name');
        }

        if (! is_int($attributeSetId)) {
            throw AdobeProductCommandCompilationException::missingField('attribute_set_id');
        }

        if (! is_int($status)) {
            throw AdobeProductCommandCompilationException::missingField('status');
        }

        if (! is_int($visibilityNumeric)) {
            throw AdobeProductCommandCompilationException::missingField('visibility_numeric');
        }

        if (! is_array($resolvedPrice)) {
            throw AdobeProductCommandCompilationException::missingField('resolved_price');
        }

        $effectiveNetPrice = $resolvedPrice['effective_net_price'] ?? null;
        $currency = $resolvedPrice['currency'] ?? null;

        if (! is_numeric($effectiveNetPrice)) {
            throw AdobeProductCommandCompilationException::invalidResolvedPrice('effective_net_price');
        }

        if (! is_string($currency) || $currency === '') {
            throw AdobeProductCommandCompilationException::invalidResolvedPrice('currency');
        }

        return new AdobeProductDesiredState(
            productVariantId: $variantId,
            sku: $sku,
            name: $name,
            attributeSetId: $attributeSetId,
            typeId: 'simple',
            status: $status,
            visibility: $visibilityNumeric,
            price: (float) $effectiveNetPrice,
            priceCurrency: $currency,
            customAttributes: $this->compileCustomAttributes($context),
        );
    }

    /**
     * @throws AdobeProductCommandCompilationException
     */
    private function compileFromSimpleChildOperation(
        AdobeProductExportSemanticOperation $operation,
    ): AdobeProductDesiredState {
        if ($operation->operation !== 'simple_child') {
            throw AdobeProductCommandCompilationException::unsupportedOperation($operation->operation);
        }

        $context = $operation->context;

        $variantId = $context['variant_id'] ?? null;
        $sku = $context['sku'] ?? null;
        $name = $context['name'] ?? null;
        $attributeSetId = $context['attribute_set_id'] ?? null;
        $status = $context['status'] ?? null;
        $visibilityNumeric = $context['visibility_numeric'] ?? null;
        $resolvedPrice = $context['resolved_price'] ?? null;

        if ((! is_string($variantId) && ! is_int($variantId)) || (string) $variantId === '') {
            throw AdobeProductCommandCompilationException::missingField('variant_id');
        }

        $variantId = (string) $variantId;

        if (! is_string($sku) || $sku === '') {
            throw AdobeProductCommandCompilationException::missingField('sku');
        }

        if (! is_string($name) || $name === '') {
            throw AdobeProductCommandCompilationException::missingField('name');
        }

        if (! is_int($attributeSetId)) {
            throw AdobeProductCommandCompilationException::missingField('attribute_set_id');
        }

        if (! is_int($status)) {
            throw AdobeProductCommandCompilationException::missingField('status');
        }

        if (! is_int($visibilityNumeric)) {
            throw AdobeProductCommandCompilationException::missingField('visibility_numeric');
        }

        if (! is_array($resolvedPrice)) {
            throw AdobeProductCommandCompilationException::missingField('resolved_price');
        }

        $effectiveNetPrice = $resolvedPrice['effective_net_price'] ?? null;
        $currency = $resolvedPrice['currency'] ?? null;

        if (! is_numeric($effectiveNetPrice)) {
            throw AdobeProductCommandCompilationException::invalidResolvedPrice('effective_net_price');
        }

        if (! is_string($currency) || $currency === '') {
            throw AdobeProductCommandCompilationException::invalidResolvedPrice('currency');
        }

        $customAttributes = $this->compileCustomAttributes($context);
        $customAttributes = $this->mergeResolvedConfigurableValues(
            $customAttributes,
            $context['resolved_configurable_values'] ?? [],
        );

        return new AdobeProductDesiredState(
            productVariantId: $variantId,
            sku: $sku,
            name: $name,
            attributeSetId: $attributeSetId,
            typeId: 'simple',
            status: $status,
            visibility: $visibilityNumeric,
            price: (float) $effectiveNetPrice,
            priceCurrency: $currency,
            customAttributes: $customAttributes,
        );
    }

    /**
     * @param  array<string, mixed>  $customAttributes
     * @param  list<array<string, mixed>>  $resolvedConfigurableValues
     * @return array<string, mixed>
     */
    private function mergeResolvedConfigurableValues(array $customAttributes, array $resolvedConfigurableValues): array
    {
        foreach ($resolvedConfigurableValues as $resolvedValue) {
            if (! is_array($resolvedValue)) {
                continue;
            }

            $externalFieldKey = $resolvedValue['external_field_key'] ?? null;
            $valueIndex = $resolvedValue['value_index'] ?? null;

            if (! is_string($externalFieldKey) || $externalFieldKey === '') {
                continue;
            }

            if ($valueIndex === null || $valueIndex === '') {
                continue;
            }

            $customAttributes[$externalFieldKey] = is_numeric($valueIndex) ? (int) $valueIndex : $valueIndex;
        }

        ksort($customAttributes);

        return $customAttributes;
    }

    /**
     * @throws AdobeProductCommandCompilationException
     */
    private function extractSimpleProductOperation(AdobeProductExportSemanticResult $semanticResult): AdobeProductExportSemanticOperation
    {
        $simpleOperations = array_values(array_filter(
            $semanticResult->operations,
            static fn (AdobeProductExportSemanticOperation $operation): bool => $operation->operation === 'simple_product',
        ));

        if ($simpleOperations === []) {
            throw AdobeProductCommandCompilationException::missingSimpleProductOperation();
        }

        if (count($simpleOperations) !== 1) {
            throw AdobeProductCommandCompilationException::multipleSimpleProductOperations();
        }

        return $simpleOperations[0];
    }

    /**
     * @throws AdobeProductCommandCompilationException
     */
    private function extractSimpleChildOperation(
        AdobeProductExportSemanticResult $semanticResult,
        string $variantId,
    ): AdobeProductExportSemanticOperation {
        $childOperations = array_values(array_filter(
            $semanticResult->operations,
            static fn (AdobeProductExportSemanticOperation $operation): bool => $operation->operation === 'simple_child',
        ));

        foreach ($childOperations as $operation) {
            if ((string) ($operation->context['variant_id'] ?? '') === $variantId) {
                return $operation;
            }
        }

        throw AdobeProductCommandCompilationException::missingField('simple_child');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function compileCustomAttributes(array $context): array
    {
        $customAttributes = [];

        foreach (['mapped_product_values', 'mapped_variant_values'] as $mappedKey) {
            $mappedValues = $context[$mappedKey] ?? [];

            if (! is_array($mappedValues)) {
                continue;
            }

            foreach ($mappedValues as $bindingId => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $externalFieldKey = $entry['external_field_key'] ?? null;
                $externalValue = $entry['external_value'] ?? null;

                if (! is_string($externalFieldKey) || $externalFieldKey === '') {
                    if ($externalValue !== null && $externalValue !== '') {
                        throw AdobeProductCommandCompilationException::unresolvedMappingBinding((string) $bindingId);
                    }

                    continue;
                }

                if (in_array($externalFieldKey, self::CONNECTOR_OWNED_EXTERNAL_KEYS, true)) {
                    continue;
                }

                if ($externalValue === null || $externalValue === '') {
                    continue;
                }

                $customAttributes[$externalFieldKey] = $externalValue;
            }
        }

        ksort($customAttributes);

        return $customAttributes;
    }
}
