<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeProductParentDesiredState
{
    /**
     * @param  array<string, mixed>  $customAttributes
     */
    public function __construct(
        public int $productId,
        public string $sku,
        public string $name,
        public int $attributeSetId,
        public string $typeId,
        public int $status,
        public int $visibility,
        public array $customAttributes,
    ) {}
}
