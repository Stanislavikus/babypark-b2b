<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model\Data;

use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteResponseInterface;
use Magento\Framework\Api\AbstractSimpleObject;

class ProductWriteResponse extends AbstractSimpleObject implements ProductWriteResponseInterface
{
    public function getAppliedState(): string
    {
        return (string) $this->_get(self::APPLIED_STATE);
    }

    public function setAppliedState(string $appliedState): ProductWriteResponseInterface
    {
        return $this->setData(self::APPLIED_STATE, $appliedState);
    }

    public function getReasonCode(): string
    {
        return (string) $this->_get(self::REASON_CODE);
    }

    public function setReasonCode(string $reasonCode): ProductWriteResponseInterface
    {
        return $this->setData(self::REASON_CODE, $reasonCode);
    }

    public function getLogicalEntityId(): int
    {
        return (int) $this->_get(self::LOGICAL_ENTITY_ID);
    }

    public function setLogicalEntityId(int $logicalEntityId): ProductWriteResponseInterface
    {
        return $this->setData(self::LOGICAL_ENTITY_ID, $logicalEntityId);
    }

    public function getSku(): string
    {
        return (string) $this->_get(self::SKU);
    }

    public function setSku(string $sku): ProductWriteResponseInterface
    {
        return $this->setData(self::SKU, $sku);
    }

    public function getPostconditionVerified(): bool
    {
        return (bool) $this->_get(self::POSTCONDITION_VERIFIED);
    }

    public function setPostconditionVerified(bool $postconditionVerified): ProductWriteResponseInterface
    {
        return $this->setData(self::POSTCONDITION_VERIFIED, $postconditionVerified);
    }

    public function getConsequentialWriteAttempts(): int
    {
        return (int) $this->_get(self::CONSEQUENTIAL_WRITE_ATTEMPTS);
    }

    public function setConsequentialWriteAttempts(int $consequentialWriteAttempts): ProductWriteResponseInterface
    {
        return $this->setData(self::CONSEQUENTIAL_WRITE_ATTEMPTS, $consequentialWriteAttempts);
    }

    public function getWarningCodes(): array
    {
        $value = $this->_get(self::WARNING_CODES);

        return is_array($value) ? array_values($value) : [];
    }

    public function setWarningCodes(array $warningCodes): ProductWriteResponseInterface
    {
        return $this->setData(self::WARNING_CODES, array_values($warningCodes));
    }
}
