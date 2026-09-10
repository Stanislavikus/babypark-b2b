<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model\Data;

use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteMappedAttributeInterface;
use Magento\Framework\Api\AbstractSimpleObject;

class ProductWriteMappedAttribute extends AbstractSimpleObject implements ProductWriteMappedAttributeInterface
{
    public function getAttributeCode(): string
    {
        return (string) $this->_get(self::ATTRIBUTE_CODE);
    }

    public function setAttributeCode(string $attributeCode): ProductWriteMappedAttributeInterface
    {
        return $this->setData(self::ATTRIBUTE_CODE, $attributeCode);
    }

    public function getValue(): string
    {
        return (string) $this->_get(self::VALUE);
    }

    public function setValue(string $value): ProductWriteMappedAttributeInterface
    {
        return $this->setData(self::VALUE, $value);
    }
}
