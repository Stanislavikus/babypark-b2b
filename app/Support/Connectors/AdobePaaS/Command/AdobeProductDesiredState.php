<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeProductDesiredState
{
    /**
     * @param  array<string, mixed>  $customAttributes
     */
    public function __construct(
        public string $productVariantId,
        public string $sku,
        public string $name,
        public int $attributeSetId,
        public string $typeId,
        public int $status,
        public int $visibility,
        public float $price,
        public string $priceCurrency,
        public array $customAttributes,
    ) {}
}
