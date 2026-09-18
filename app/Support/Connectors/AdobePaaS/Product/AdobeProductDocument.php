<?php

namespace App\Support\Connectors\AdobePaaS\Product;

final readonly class AdobeProductDocument
{
    /**
     * @param  array<string, mixed>  $originalPayload
     */
    private function __construct(
        public int $logicalEntityId,
        public string $sku,
        public string $typeId,
        public array $originalPayload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        if (array_is_list($payload)) {
            throw new AdobeProductDocumentReadException('Magento Product response must be a JSON object.');
        }

        $logicalEntityId = $payload['id'] ?? null;
        $sku = $payload['sku'] ?? null;
        $typeId = $payload['type_id'] ?? null;

        if (! is_int($logicalEntityId) || $logicalEntityId <= 0) {
            throw new AdobeProductDocumentReadException('Magento Product response field `id` is invalid.');
        }

        if (! is_string($sku) || $sku === '') {
            throw new AdobeProductDocumentReadException('Magento Product response field `sku` is invalid.');
        }

        if (! is_string($typeId) || $typeId === '') {
            throw new AdobeProductDocumentReadException('Magento Product response field `type_id` is invalid.');
        }

        return new self($logicalEntityId, $sku, $typeId, $payload);
    }

    /**
     * @return list<string>
     */
    public function categoryIds(): array
    {
        $extensionAttributes = $this->originalPayload['extension_attributes'] ?? null;

        if (! is_array($extensionAttributes) || array_is_list($extensionAttributes)) {
            return [];
        }

        $links = $extensionAttributes['category_links'] ?? null;

        if (! is_array($links) || ! array_is_list($links)) {
            return [];
        }

        $ids = [];

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $categoryId = $link['category_id'] ?? null;

            if (is_int($categoryId) && $categoryId > 0) {
                $ids[] = (string) $categoryId;

                continue;
            }

            if (is_string($categoryId) && preg_match('/^[1-9][0-9]*$/', $categoryId) === 1) {
                $ids[] = $categoryId;
            }
        }

        $result = array_values(array_unique($ids, SORT_STRING));
        sort($result, SORT_NATURAL);

        return $result;
    }

    /**
     * @return array{present: bool, value: mixed}
     */
    public function externalValue(string $externalFieldKey): array
    {
        if ($externalFieldKey === '') {
            return ['present' => false, 'value' => null];
        }

        if (array_key_exists($externalFieldKey, $this->originalPayload)) {
            return ['present' => true, 'value' => $this->originalPayload[$externalFieldKey]];
        }

        $customAttribute = $this->customAttributeValue($externalFieldKey);

        if ($customAttribute['present']) {
            return $customAttribute;
        }

        if (! str_contains($externalFieldKey, '.')) {
            return ['present' => false, 'value' => null];
        }

        return $this->nestedValue($externalFieldKey);
    }

    /**
     * @return array{present: bool, value: mixed}
     */
    private function customAttributeValue(string $attributeCode): array
    {
        $attributes = $this->originalPayload['custom_attributes'] ?? null;

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
        $value = $this->originalPayload;

        foreach ($segments as $segment) {
            if ($segment === '' || ! is_array($value) || ! array_key_exists($segment, $value)) {
                return ['present' => false, 'value' => null];
            }

            $value = $value[$segment];
        }

        return ['present' => true, 'value' => $value];
    }
}
