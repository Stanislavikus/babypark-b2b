<?php

namespace App\Support\Connectors\AdobePaaS;

final readonly class AdobeProductExportExecutionMetadata
{
    /**
     * @param  list<array{attribute_set_id: int, attribute_set_name: string}>  $attributeSets
     * @param  array<string, AdobeAttributeMetadata>  $attributes  keyed by attribute code for selectedAttributeSetId
     * @param  array<int, array<string, AdobeAttributeMetadata>>  $attributesByAttributeSetId
     */
    public function __construct(
        public int $selectedAttributeSetId,
        public array $attributeSets,
        public array $attributes = [],
        public array $attributesByAttributeSetId = [],
    ) {}

    public function forAttributeSetId(int $attributeSetId): self
    {
        if ($attributeSetId === $this->selectedAttributeSetId) {
            return $this;
        }

        return new self(
            selectedAttributeSetId: $attributeSetId,
            attributeSets: $this->attributeSets,
            attributes: $this->attributesByAttributeSetId[$attributeSetId] ?? [],
            attributesByAttributeSetId: $this->attributesByAttributeSetId,
        );
    }

    public function hasAttributeSet(int $attributeSetId): bool
    {
        foreach ($this->attributeSets as $attributeSet) {
            if (($attributeSet['attribute_set_id'] ?? null) === $attributeSetId) {
                return true;
            }
        }

        return false;
    }

    public function attributeByCode(string $code): ?AdobeAttributeMetadata
    {
        return $this->attributes[$code] ?? null;
    }

    public function optionExists(string $attributeCode, string $optionValue): bool
    {
        $attribute = $this->attributeByCode($attributeCode);

        if ($attribute === null) {
            return false;
        }

        return array_key_exists($optionValue, $attribute->options);
    }

    public function isConfigurableCompatible(string $attributeCode): bool
    {
        $attribute = $this->attributeByCode($attributeCode);

        if ($attribute === null) {
            return false;
        }

        return $attribute->frontendInput === 'select' && $attribute->scope === 'global';
    }
}
