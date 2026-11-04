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

    public function getApplicationVersion(): ?string
    {
        $version = $this->_get(self::APPLICATION_VERSION);

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function setApplicationVersion(?string $applicationVersion): HandshakeResponseInterface
    {
        return $this->setData(
            self::APPLICATION_VERSION,
            is_string($applicationVersion) && trim($applicationVersion) !== '' ? trim($applicationVersion) : null,
        );
    }

    public function getPhpVersion(): ?string
    {
        $version = $this->_get(self::PHP_VERSION);

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function setPhpVersion(?string $phpVersion): HandshakeResponseInterface
    {
        return $this->setData(
            self::PHP_VERSION,
            is_string($phpVersion) && trim($phpVersion) !== '' ? trim($phpVersion) : null,
        );
    }
}
