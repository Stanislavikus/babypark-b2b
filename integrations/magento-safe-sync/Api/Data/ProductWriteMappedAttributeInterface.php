<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api\Data;

interface ProductWriteMappedAttributeInterface
{
    public const ATTRIBUTE_CODE = 'attribute_code';

    public const VALUE = 'value';

    /**
     * Gets the mapped Magento attribute code.
     *
     * @return string Mapped Magento attribute code.
     */
    public function getAttributeCode(): string;

    /**
     * Sets the mapped Magento attribute code.
     *
     * @param  string  $attributeCode  Mapped Magento attribute code.
     * @return $this
     */
    public function setAttributeCode(string $attributeCode): self;

    /**
     * Gets the mapped Magento attribute value.
     *
     * @return string Mapped Magento attribute value.
     */
    public function getValue(): string;

    /**
     * Sets the mapped Magento attribute value.
     *
     * @param  string  $value  Mapped Magento attribute value.
     * @return $this
     */
    public function setValue(string $value): self;
}
