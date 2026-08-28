<?php

namespace App\Support\Connectors\AdobePaaS\Product;

final readonly class AdobeProductDocument
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public int $logicalEntityId,
        public string $sku,
        public string $typeId,
        public array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, int $expectedLogicalEntityId, string $expectedSku): self
    {
        $logicalEntityId = $payload['id'] ?? null;
        $sku = $payload['sku'] ?? null;
        $typeId = $payload['type_id'] ?? null;

        if (! is_int($logicalEntityId) || $logicalEntityId <= 0) {
            throw new AdobeProductReadException('Magento Product response field `id` is invalid.');
        }

        if (! is_string($sku) || $sku === '') {
            throw new AdobeProductReadException('Magento Product response field `sku` is invalid.');
        }

        if (! is_string($typeId) || $typeId === '') {
            throw new AdobeProductReadException('Magento Product response field `type_id` is invalid.');
        }

        if ($logicalEntityId !== $expectedLogicalEntityId) {
            throw new AdobeProductReadException('Magento Product logical entity identity mismatch.');
        }

        if ($sku !== $expectedSku) {
            throw new AdobeProductReadException('Magento Product SKU mismatch.');
        }

        return new self($logicalEntityId, $sku, $typeId, $payload);
    }

    /**
     * Resolve a FieldMapping external key from the complete Magento Product payload.
     *
     * Plain keys first address stable ProductInterface fields and then
     * custom_attributes[].attribute_code. Dotted keys may address nested
     * Product structures such as extension_attributes.stock_item.qty.
     *
     * @return array{present: bool, value: mixed}
     */
    public function externalValue(string $externalFieldKey): array
    {
        if ($externalFieldKey === '') {
            return ['present' => false, 'value' => null];
        }

        if (array_key_exists($externalFieldKey, $this->payload)) {
            return ['present' => true, 'value' => $this->payload[$externalFieldKey]];
        }

        $customAttribute = $this->customAttributeValue($externalFieldKey);

        if ($customAttribute['present']) {
            return $customAttribute;
        }

        if (str_contains($externalFieldKey, '.')) {
            return $this->nestedValue($externalFieldKey);
        }

        return ['present' => false, 'value' => null];
    }

    /**
     * @return array{present: bool, value: mixed}
     */
    private function customAttributeValue(string $attributeCode): array
    {
        $attributes = $this->payload['custom_attributes'] ?? null;

        if (! is_array($attributes) || ! array_is_list($attributes)) {
            return ['present' => false, 'value' => null];
        }

        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || ($attribute['attribute_code'] ?? null) !== $attributeCode) {
                continue;
            }

            if (! array_key_exists('value', $attribute)) {
                return ['present' => false, 'value' => null];
            }

            return ['present' => true, 'value' => $attribute['value']];
        }

        return ['present' => false, 'value' => null];
    }

    /**
     * @return array{present: bool, value: mixed}
     */
    private function nestedValue(string $externalFieldKey): array
    {
        $segments = explode('.', $externalFieldKey);
        $value = $this->payload;

        foreach ($segments as $segment) {
            if ($segment === '' || ! is_array($value) || ! array_key_exists($segment, $value)) {
                return ['present' => false, 'value' => null];
            }

            $value = $value[$segment];
        }

        return ['present' => true, 'value' => $value];
    }
}
