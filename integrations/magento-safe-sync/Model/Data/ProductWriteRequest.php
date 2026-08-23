<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model\Data;

use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteRequestInterface;
use Magento\Framework\Api\AbstractSimpleObject;

class ProductWriteRequest extends AbstractSimpleObject implements ProductWriteRequestInterface
{
    public function getExpectedSku(): string
    {
        return (string) $this->_get(self::EXPECTED_SKU);
    }

    public function setExpectedSku(string $expectedSku): ProductWriteRequestInterface
    {
        return $this->setData(self::EXPECTED_SKU, $expectedSku);
    }

    public function getName(): ?string
    {
        $value = $this->_get(self::NAME);

        return is_string($value) ? $value : null;
    }

    public function setName(?string $name): ProductWriteRequestInterface
    {
        return $this->setData(self::NAME, $name);
    }

    public function getStatus(): ?int
    {
        $value = $this->_get(self::STATUS);

        return is_int($value) ? $value : null;
    }

    public function setStatus(?int $status): ProductWriteRequestInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getVisibility(): ?int
    {
        $value = $this->_get(self::VISIBILITY);

        return is_int($value) ? $value : null;
    }

    public function setVisibility(?int $visibility): ProductWriteRequestInterface
    {
        return $this->setData(self::VISIBILITY, $visibility);
    }

    public function getPrice(): ?float
    {
        $value = $this->_get(self::PRICE);

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    public function setPrice(?float $price): ProductWriteRequestInterface
    {
        return $this->setData(self::PRICE, $price);
    }

    public function getCustomAttributes(): array
    {
        $value = $this->_get(self::CUSTOM_ATTRIBUTES);

        return is_array($value) ? array_values($value) : [];
    }

    public function setCustomAttributes(array $customAttributes): ProductWriteRequestInterface
    {
        return $this->setData(self::CUSTOM_ATTRIBUTES, array_values($customAttributes));
    }
}
