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

    /**
     * Gets the exact SKU precondition for the write.
     *
     * @return string Exact expected SKU.
     */
    public function getExpectedSku(): string;

    /**
     * Sets the exact SKU precondition for the write.
     *
     * @param  string  $expectedSku  Exact expected SKU.
     * @return $this
     */
    public function setExpectedSku(string $expectedSku): self;

    /**
     * Gets the requested product name mutation.
     *
     * @return string|null Requested product name mutation.
     */
    public function getName(): ?string;

    /**
     * Sets the requested product name mutation.
     *
     * @param  string|null  $name  Requested product name mutation.
     * @return $this
     */
    public function setName(?string $name): self;

    /**
     * Gets the requested product status mutation.
     *
     * @return int|null Requested product status mutation.
     */
    public function getStatus(): ?int;

    /**
     * Sets the requested product status mutation.
     *
     * @param  int|null  $status  Requested product status mutation.
     * @return $this
     */
    public function setStatus(?int $status): self;

    /**
     * Gets the requested product visibility mutation.
     *
     * @return int|null Requested product visibility mutation.
     */
    public function getVisibility(): ?int;

    /**
     * Sets the requested product visibility mutation.
     *
     * @param  int|null  $visibility  Requested product visibility mutation.
     * @return $this
     */
    public function setVisibility(?int $visibility): self;

    /**
     * Gets the requested product price mutation.
     *
     * @return float|null Requested product price mutation.
     */
    public function getPrice(): ?float;

    /**
     * Sets the requested product price mutation.
     *
     * @param  float|null  $price  Requested product price mutation.
     * @return $this
     */
    public function setPrice(?float $price): self;

    /**
     * Gets the requested mapped attribute mutations.
     *
     * @return ProductWriteMappedAttributeInterface[] Requested mapped attribute mutations.
     */
    public function getMappedAttributes(): array;

    /**
     * Sets the requested mapped attribute mutations.
     *
     * @param  ProductWriteMappedAttributeInterface[]  $mappedAttributes  Requested mapped attribute mutations.
     * @return $this
     */
    public function setMappedAttributes(array $mappedAttributes): self;
}
