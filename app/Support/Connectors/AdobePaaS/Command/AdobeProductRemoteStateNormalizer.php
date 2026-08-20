<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final class AdobeProductRemoteStateNormalizer
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
     * @param  array<string, mixed>  $productPayload
     */
    public function normalize(array $productPayload, string $expectedSku): ?AdobeProductObservedState
    {
        $sku = $productPayload['sku'] ?? null;

        if (! is_string($sku) || $sku !== $expectedSku) {
            return null;
        }

        $name = $productPayload['name'] ?? null;
        $attributeSetId = $productPayload['attribute_set_id'] ?? null;
        $typeId = $productPayload['type_id'] ?? null;
        $status = $productPayload['status'] ?? null;
        $visibility = $productPayload['visibility'] ?? null;
        $price = $productPayload['price'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        if (! is_numeric($attributeSetId)) {
            return null;
        }

        if (! is_string($typeId) || $typeId === '') {
            return null;
        }

        if (! is_numeric($status)) {
            return null;
        }

        if (! is_numeric($visibility)) {
            return null;
        }

        if (! is_numeric($price)) {
            return null;
        }

        return new AdobeProductObservedState(
            sku: $sku,
            name: $name,
            attributeSetId: (int) $attributeSetId,
            typeId: $typeId,
            status: (int) $status,
            visibility: (int) $visibility,
            price: (float) $price,
            customAttributes: $this->normalizeCustomAttributes($productPayload['custom_attributes'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $productPayload
     */
    public function normalizeParent(array $productPayload, string $expectedSku): ?AdobeProductParentObservedState
    {
        $sku = $productPayload['sku'] ?? null;

        if (! is_string($sku) || $sku !== $expectedSku) {
            return null;
        }

        $name = $productPayload['name'] ?? null;
        $attributeSetId = $productPayload['attribute_set_id'] ?? null;
        $typeId = $productPayload['type_id'] ?? null;
        $status = $productPayload['status'] ?? null;
        $visibility = $productPayload['visibility'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        if (! is_numeric($attributeSetId)) {
            return null;
        }

        if (! is_string($typeId) || $typeId === '') {
            return null;
        }

        if (! is_numeric($status)) {
            return null;
        }

        if (! is_numeric($visibility)) {
            return null;
        }

        return new AdobeProductParentObservedState(
            sku: $sku,
            name: $name,
            attributeSetId: (int) $attributeSetId,
            typeId: $typeId,
            status: (int) $status,
            visibility: (int) $visibility,
            customAttributes: $this->normalizeCustomAttributes($productPayload['custom_attributes'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCustomAttributes(mixed $rawCustomAttributes): array
    {
        if (! is_array($rawCustomAttributes)) {
            return [];
        }

        $attributes = [];

        foreach ($rawCustomAttributes as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $attributeCode = $entry['attribute_code'] ?? null;
            $value = $entry['value'] ?? null;

            if (! is_string($attributeCode) || $attributeCode === '') {
                continue;
            }

            if (in_array($attributeCode, self::CONNECTOR_OWNED_EXTERNAL_KEYS, true)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $attributes[$attributeCode] = $value;
        }

        ksort($attributes);

        return $attributes;
    }
}
