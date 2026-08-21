<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model\Data;

use B2BPlatform\MagentoSafeSync\Api\Data\HandshakeResponseInterface;
use Magento\Framework\Api\AbstractSimpleObject;

class HandshakeResponse extends AbstractSimpleObject implements HandshakeResponseInterface
{
    public function getContractVersion(): string
    {
        return (string) $this->_get(self::CONTRACT_VERSION);
    }

    public function setContractVersion(string $contractVersion): HandshakeResponseInterface
    {
        return $this->setData(self::CONTRACT_VERSION, $contractVersion);
    }

    public function getModuleVersion(): string
    {
        return (string) $this->_get(self::MODULE_VERSION);
    }

    public function setModuleVersion(string $moduleVersion): HandshakeResponseInterface
    {
        return $this->setData(self::MODULE_VERSION, $moduleVersion);
    }

    public function getSupportedOperationFamilies(): array
    {
        $families = $this->_get(self::SUPPORTED_OPERATION_FAMILIES);

        return is_array($families) ? array_values($families) : [];
    }

    public function setSupportedOperationFamilies(array $supportedOperationFamilies): HandshakeResponseInterface
    {
        return $this->setData(self::SUPPORTED_OPERATION_FAMILIES, array_values($supportedOperationFamilies));
    }
}
