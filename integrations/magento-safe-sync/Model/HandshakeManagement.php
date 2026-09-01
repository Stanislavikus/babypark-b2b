<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model;

use B2BPlatform\MagentoSafeSync\Api\Data\HandshakeResponseInterface;
use B2BPlatform\MagentoSafeSync\Api\HandshakeManagementInterface;
use B2BPlatform\MagentoSafeSync\Model\Data\HandshakeResponseFactory;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\ModuleListInterface;

final class HandshakeManagement implements HandshakeManagementInterface
{
    public function __construct(
        private readonly HandshakeResponseFactory $responseFactory,
        private readonly ModuleListInterface $moduleList,
        private readonly ProductMetadataInterface $productMetadata,
    ) {}

    public function handshake(): HandshakeResponseInterface
    {
        $moduleInfo = $this->moduleList->getOne(SafeSyncContract::MODULE_NAME) ?? [];
        $moduleVersion = $this->requireModuleVersion($moduleInfo);

        $response = $this->responseFactory->create();
        $response->setContractVersion(SafeSyncContract::CONTRACT_VERSION);
        $response->setModuleVersion($moduleVersion);
        $response->setSupportedOperationFamilies([
            SafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
            SafeSyncContract::SIMPLE_PRODUCT_WRITE_FAMILY,
        ]);
        $response->setApplicationVersion($this->resolveApplicationVersion());
        $response->setPhpVersion($this->resolvePhpVersion());

        return $response;
    }

    private function resolveApplicationVersion(): ?string
    {
        $version = $this->productMetadata->getVersion();

        $version = is_string($version) ? trim($version) : '';

        return $version !== '' ? $version : null;
    }

    private function resolvePhpVersion(): ?string
    {
        $version = phpversion();
        $version = is_string($version) ? trim($version) : '';

        return $version !== '' ? $version : null;
    }

    /**
     * @param  array<string, mixed>  $moduleInfo
     */
    private function requireModuleVersion(array $moduleInfo): string
    {
        $moduleVersion = $moduleInfo['setup_version'] ?? null;

        if (! is_string($moduleVersion)) {
            throw new LocalizedException(__('safe_sync_module_version_unavailable'));
        }

        if (
            $moduleVersion === ''
            || $moduleVersion === '0.0.0'
            || trim($moduleVersion) !== $moduleVersion
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]*$/', $moduleVersion) !== 1
        ) {
            throw new LocalizedException(__('safe_sync_module_version_unavailable'));
        }

        return $moduleVersion;
    }
}
