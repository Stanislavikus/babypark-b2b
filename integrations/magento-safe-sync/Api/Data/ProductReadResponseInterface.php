<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api\Data;

interface ProductReadResponseInterface
{
    public const LOGICAL_ENTITY_ID = 'logical_entity_id';

    public const SKU = 'sku';

    public const TYPE_ID = 'type_id';

    public const NAME = 'name';

    /**
     * Gets the verified logical product identity.
     *
     * @return int Verified logical entity identifier.
     */
    public function getLogicalEntityId(): int;

    /**
     * Sets the verified logical product identity.
     *
     * @param  int  $logicalEntityId  Verified logical entity identifier.
     * @return $this
     */
    public function setLogicalEntityId(int $logicalEntityId): self;

    /**
     * Gets the verified product SKU.
     *
     * @return string Verified product SKU.
     */
    public function getSku(): string;

    /**
     * Sets the verified product SKU.
     *
     * @param  string  $sku  Verified product SKU.
     * @return $this
     */
    public function setSku(string $sku): self;

    /**
     * Gets the verified Magento product type.
     *
     * @return string Verified Magento product type.
     */
    public function getTypeId(): string;

    /**
     * Sets the verified Magento product type.
     *
     * @param  string  $typeId  Verified Magento product type.
     * @return $this
     */
    public function setTypeId(string $typeId): self;

    /**
     * Gets the verified product name.
     *
     * @return string Verified product name.
     */
    public function getName(): string;

    /**
     * Sets the verified product name.
     *
     * @param  string  $name  Verified product name.
     * @return $this
     */
    public function setName(string $name): self;
}
