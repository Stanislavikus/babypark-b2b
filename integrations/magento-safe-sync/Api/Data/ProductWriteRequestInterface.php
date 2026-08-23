<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api\Data;

interface ProductWriteRequestInterface
{
    public const EXPECTED_SKU = 'expected_sku';

    public const NAME = 'name';

    public const STATUS = 'status';

    public const VISIBILITY = 'visibility';

    public const PRICE = 'price';

    public const MAPPED_ATTRIBUTES = 'mapped_attributes';

    public function getExpectedSku(): string;

    public function setExpectedSku(string $expectedSku): self;

    public function getName(): ?string;

    public function setName(?string $name): self;

    public function getStatus(): ?int;

    public function setStatus(?int $status): self;

    public function getVisibility(): ?int;

    public function setVisibility(?int $visibility): self;

    public function getPrice(): ?float;

    public function setPrice(?float $price): self;

    /**
     * @return ProductWriteMappedAttributeInterface[]
     */
    public function getMappedAttributes(): array;

    /**
     * @param  ProductWriteMappedAttributeInterface[]  $mappedAttributes
     */
    public function setMappedAttributes(array $mappedAttributes): self;
}
