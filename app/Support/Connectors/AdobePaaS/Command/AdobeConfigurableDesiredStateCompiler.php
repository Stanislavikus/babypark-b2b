<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobeAttributeMetadata;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticOperation;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;

final class AdobeConfigurableDesiredStateCompiler
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

    public function __construct(
        private readonly AdobeConfigurableParentSkuGenerator $parentSkuGenerator,
    ) {}

    /**
     * @throws AdobeProductCommandCompilationException
     */
    public function compile(
        AdobeProductExportSemanticResult $semanticResult,
        string $workspaceId,
        ?AdobeProductExportExecutionMetadata $metadata,
    ): AdobeConfigurableDesiredState {
        if ($semanticResult->hasBlockingFindings()) {
            throw AdobeProductCommandCompilationException::blockingSemanticFindings();
        }

        $parentOperation = $this->extractSingleOperation($semanticResult, 'configurable_parent');
        $parentContext = $parentOperation->context;

        $productId = $this->requireInt($parentContext, 'product_id', 'product_id');
        $parentSku = $this->parentSkuGenerator->generate($workspaceId, $productId);

        $name = $this->requireString($parentContext, 'name');
        $attributeSetId = $this->requireInt($parentContext, 'attribute_set_id', 'attribute_set_id');
        $status = $this->requireInt($parentContext, 'status', 'status');
        $visibilityNumeric = $this->requireInt($parentContext, 'visibility_numeric', 'visibility_numeric');

        $parent = new AdobeProductParentDesiredState(
            productId: $productId,
            sku: $parentSku,
            name: $name,
            attributeSetId: $attributeSetId,
            typeId: 'configurable',
            status: $status,
            visibility: $visibilityNumeric,
            customAttributes: $this->compileMappedCustomAttributes($parentContext['mapped_product_values'] ?? []),
        );

        $attributeOperations = $this->operationsOfType($semanticResult, 'configurable_attribute');
        $optionAssignments = $this->operationsOfType($semanticResult, 'option_assignment');
        $options = $this->compileOptions($attributeOperations, $optionAssignments, $metadata);

        $childOperations = $this->operationsOfType($semanticResult, 'simple_child');
        $linkOperations = $this->operationsOfType($semanticResult, 'child_link');

        $activeChildVariantIds = [];
        $childLinks = [];

        foreach ($childOperations as $childOperation) {
            $variantId = $this->requireString($childOperation->context, 'variant_id');
            $activeChildVariantIds[] = $variantId;
        }

        sort($activeChildVariantIds, SORT_STRING);

        foreach ($linkOperations as $linkOperation) {
            $variantId = $this->requireString($linkOperation->context, 'variant_id');
            $childOperation = $this->findChildOperation($childOperations, $variantId);
            $childSku = $this->requireString($childOperation->context, 'sku');

            $childLinks[] = new AdobeConfigurableChildLinkDesiredState(
                variantId: $variantId,
                childSku: $childSku,
            );
        }

        usort(
            $childLinks,
            static fn (AdobeConfigurableChildLinkDesiredState $left, AdobeConfigurableChildLinkDesiredState $right): int => strcmp($left->variantId, $right->variantId),
        );

        return new AdobeConfigurableDesiredState(
            productId: $productId,
            parentSku: $parentSku,
            parent: $parent,
            options: $options,
            activeChildVariantIds: $activeChildVariantIds,
            childLinks: $childLinks,
        );
    }

    /**
     * @param  list<AdobeProductExportSemanticOperation>  $attributeOperations
     * @param  list<AdobeProductExportSemanticOperation>  $optionAssignments
     * @return list<AdobeConfigurableOptionDesiredState>
     *
     * @throws AdobeProductCommandCompilationException
     */
    private function compileOptions(
        array $attributeOperations,
        array $optionAssignments,
        ?AdobeProductExportExecutionMetadata $metadata,
    ): array {
        $options = [];
        $position = 0;

        foreach ($attributeOperations as $attributeOperation) {
            $externalFieldKey = $this->requireString($attributeOperation->context, 'external_field_key');
            $attributeId = $this->requireInt($attributeOperation->context, 'attribute_id', 'attribute_id');
            $bindingId = $attributeOperation->context['field_binding_id'] ?? null;

            $values = [];

            foreach ($optionAssignments as $optionAssignment) {
                if (($optionAssignment->context['field_binding_id'] ?? null) !== $bindingId) {
                    continue;
                }

                $valueIndexRaw = $optionAssignment->context['value_index'] ?? null;

                if (! is_numeric($valueIndexRaw)) {
                    throw AdobeProductCommandCompilationException::missingField('value_index');
                }

                $valueIndex = (int) $valueIndexRaw;
                $label = $this->resolveOptionLabel($metadata, $externalFieldKey, (string) $valueIndexRaw);

                $values[] = new AdobeConfigurableOptionValueDesiredState(
                    valueIndex: $valueIndex,
                    label: $label,
                );
            }

            usort(
                $values,
                static fn (AdobeConfigurableOptionValueDesiredState $left, AdobeConfigurableOptionValueDesiredState $right): int => $left->valueIndex <=> $right->valueIndex,
            );

            $options[] = new AdobeConfigurableOptionDesiredState(
                externalFieldKey: $externalFieldKey,
                attributeId: $attributeId,
                label: $this->resolveDimensionLabel($metadata, $externalFieldKey),
                position: $position,
                values: $values,
            );

            $position++;
        }

        return $options;
    }

    private function resolveDimensionLabel(
        ?AdobeProductExportExecutionMetadata $metadata,
        string $externalFieldKey,
    ): string {
        $attribute = $metadata?->attributeByCode($externalFieldKey);

        if ($attribute !== null && $attribute->code !== '') {
            return $externalFieldKey;
        }

        return $externalFieldKey;
    }

    private function resolveOptionLabel(
        ?AdobeProductExportExecutionMetadata $metadata,
        string $externalFieldKey,
        string $valueIndex,
    ): string {
        $attribute = $metadata?->attributeByCode($externalFieldKey);

        if ($attribute instanceof AdobeAttributeMetadata) {
            $label = $attribute->options[$valueIndex] ?? null;

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return $valueIndex;
    }

    /**
     * @param  list<AdobeProductExportSemanticOperation>  $childOperations
     *
     * @throws AdobeProductCommandCompilationException
     */
    private function findChildOperation(array $childOperations, string $variantId): AdobeProductExportSemanticOperation
    {
        foreach ($childOperations as $childOperation) {
            if ((string) ($childOperation->context['variant_id'] ?? '') === $variantId) {
                return $childOperation;
            }
        }

        throw AdobeProductCommandCompilationException::missingField('simple_child');
    }

    /**
     * @throws AdobeProductCommandCompilationException
     */
    private function extractSingleOperation(
        AdobeProductExportSemanticResult $semanticResult,
        string $operationType,
    ): AdobeProductExportSemanticOperation {
        $operations = $this->operationsOfType($semanticResult, $operationType);

        if ($operations === []) {
            throw AdobeProductCommandCompilationException::unsupportedOperation($operationType);
        }

        if (count($operations) !== 1) {
            throw AdobeProductCommandCompilationException::unsupportedOperation($operationType.'_duplicate');
        }

        return $operations[0];
    }

    /**
     * @return list<AdobeProductExportSemanticOperation>
     */
    private function operationsOfType(
        AdobeProductExportSemanticResult $semanticResult,
        string $operationType,
    ): array {
        return array_values(array_filter(
            $semanticResult->operations,
            static fn (AdobeProductExportSemanticOperation $operation): bool => $operation->operation === $operationType,
        ));
    }

    /**
     * @param  array<string, mixed>  $mappedValues
     * @return array<string, mixed>
     */
    private function compileMappedCustomAttributes(array $mappedValues): array
    {
        $customAttributes = [];

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

        ksort($customAttributes);

        return $customAttributes;
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws AdobeProductCommandCompilationException
     */
    private function requireString(array $context, string $field): string
    {
        $value = $context[$field] ?? null;

        if (! is_string($value) || $value === '') {
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }

            throw AdobeProductCommandCompilationException::missingField($field);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws AdobeProductCommandCompilationException
     */
    private function requireInt(array $context, string $field, string $errorField): int
    {
        $value = $context[$field] ?? null;

        if (! is_int($value)) {
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }

            throw AdobeProductCommandCompilationException::missingField($errorField);
        }

        return $value;
    }
}
