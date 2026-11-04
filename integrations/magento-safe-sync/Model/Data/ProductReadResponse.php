<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model\Data;

use B2BPlatform\MagentoSafeSync\Api\Data\ProductReadResponseInterface;
use Magento\Framework\Api\AbstractSimpleObject;

class ProductReadResponse extends AbstractSimpleObject implements ProductReadResponseInterface
{
    public function getLogicalEntityId(): int
    {
        return (int) $this->_get(self::LOGICAL_ENTITY_ID);
    }

    public function setLogicalEntityId(int $logicalEntityId): ProductReadResponseInterface
    {
        return $this->setData(self::LOGICAL_ENTITY_ID, $logicalEntityId);
    }

    public function getSku(): string
    {
        return (string) $this->_get(self::SKU);
    }

    public function setSku(string $sku): ProductReadResponseInterface
    {
        return $this->setData(self::SKU, $sku);
    }

    public function getTypeId(): string
    {
        return (string) $this->_get(self::TYPE_ID);
    }

    public function setTypeId(string $typeId): ProductReadResponseInterface
    {
        return $this->setData(self::TYPE_ID, $typeId);
    }

    public function getName(): string
    {
        return (string) $this->_get(self::NAME);
    }

    public function setName(string $name): ProductReadResponseInterface
    {
        return $this->setData(self::NAME, $name);
    }
}
