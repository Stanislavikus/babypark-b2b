<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeProductObservedState
{
    /**
     * @param  array<string, mixed>  $customAttributes
     */
    public function __construct(
        public string $sku,
        public string $name,
        public int $attributeSetId,
        public string $typeId,
        public int $status,
        public int $visibility,
        public float $price,
        public array $customAttributes,
    ) {}
}
