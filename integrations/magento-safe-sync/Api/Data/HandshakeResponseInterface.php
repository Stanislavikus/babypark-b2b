<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api\Data;

interface HandshakeResponseInterface
{
    public const CONTRACT_VERSION = 'contract_version';

    public const MODULE_VERSION = 'module_version';

    public const SUPPORTED_OPERATION_FAMILIES = 'supported_operation_families';

    /**
     * Gets the negotiated Safe Sync contract version.
     *
     * @return string Safe Sync contract version.
     */
    public function getContractVersion(): string;

    /**
     * Sets the negotiated Safe Sync contract version.
     *
     * @param  string  $contractVersion  Safe Sync contract version.
     * @return $this
     */
    public function setContractVersion(string $contractVersion): self;

    /**
     * Gets the installed Safe Sync module version.
     *
     * @return string Safe Sync module version.
     */
    public function getModuleVersion(): string;

    /**
     * Sets the installed Safe Sync module version.
     *
     * @param  string  $moduleVersion  Safe Sync module version.
     * @return $this
     */
    public function setModuleVersion(string $moduleVersion): self;

    /**
     * Gets the supported Safe Sync operation families.
     *
     * @return string[] Supported operation family codes.
     */
    public function getSupportedOperationFamilies(): array;

    /**
     * Sets the supported Safe Sync operation families.
     *
     * @param  string[]  $supportedOperationFamilies  Supported operation family codes.
     * @return $this
     */
    public function setSupportedOperationFamilies(array $supportedOperationFamilies): self;
}
