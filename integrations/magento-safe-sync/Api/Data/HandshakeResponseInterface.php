<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api\Data;

interface HandshakeResponseInterface
{
    public const CONTRACT_VERSION = 'contract_version';

    public const MODULE_VERSION = 'module_version';

    public const SUPPORTED_OPERATION_FAMILIES = 'supported_operation_families';

    public function getContractVersion(): string;

    public function setContractVersion(string $contractVersion): self;

    public function getModuleVersion(): string;

    public function setModuleVersion(string $moduleVersion): self;

    /**
     * @return string[]
     */
    public function getSupportedOperationFamilies(): array;

    /**
     * @param  string[]  $supportedOperationFamilies
     */
    public function setSupportedOperationFamilies(array $supportedOperationFamilies): self;
}
