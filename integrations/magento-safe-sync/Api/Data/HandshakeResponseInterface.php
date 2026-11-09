<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api\Data;

interface HandshakeResponseInterface
{
    public const CONTRACT_VERSION = 'contract_version';

    public const MODULE_VERSION = 'module_version';

    public const SUPPORTED_OPERATION_FAMILIES = 'supported_operation_families';

    /**
     * Optional Magento application version (e.g. 2.4.7).
     *
     * Exposed for diagnostics and support; must not be required by clients.
     */
    public const APPLICATION_VERSION = 'application_version';

    /**
     * Optional PHP runtime version (e.g. 8.2.0).
     *
     * Exposed for diagnostics and support; must not be required by clients.
     */
    public const PHP_VERSION = 'php_version';

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

    /**
     * Gets the Magento application version (optional).
     *
     * @return string|null Magento version.
     */
    public function getApplicationVersion(): ?string;

    /**
     * Sets the Magento application version (optional).
     *
     * @param  string|null  $applicationVersion  Magento version.
     * @return $this
     */
    public function setApplicationVersion(?string $applicationVersion): self;

    /**
     * Gets the PHP runtime version (optional).
     *
     * @return string|null PHP version.
     */
    public function getPhpVersion(): ?string;

    /**
     * Sets the PHP runtime version (optional).
     *
     * @param  string|null  $phpVersion  PHP version.
     * @return $this
     */
    public function setPhpVersion(?string $phpVersion): self;
}
