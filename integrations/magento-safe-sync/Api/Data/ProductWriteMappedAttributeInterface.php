<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api\Data;

interface ProductWriteMappedAttributeInterface
{
    public const ATTRIBUTE_CODE = 'attribute_code';

    public const VALUE = 'value';

    public function getAttributeCode(): string;

    public function setAttributeCode(string $attributeCode): self;

    public function getValue(): string;

    public function setValue(string $value): self;
}
