<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model;

use B2BPlatform\MagentoSafeSync\Api\Data\HandshakeResponseInterface;
use B2BPlatform\MagentoSafeSync\Api\HandshakeManagementInterface;
use B2BPlatform\MagentoSafeSync\Model\Data\HandshakeResponseFactory;
use Magento\Framework\Module\ModuleListInterface;

final class HandshakeManagement implements HandshakeManagementInterface
{
    public function __construct(
        private readonly HandshakeResponseFactory $responseFactory,
        private readonly ModuleListInterface $moduleList,
    ) {}

    public function handshake(): HandshakeResponseInterface
    {
        $moduleInfo = $this->moduleList->getOne(SafeSyncContract::MODULE_NAME) ?? [];
        $moduleVersion = (string) ($moduleInfo['setup_version'] ?? '0.0.0');

        $response = $this->responseFactory->create();
        $response->setContractVersion(SafeSyncContract::CONTRACT_VERSION);
        $response->setModuleVersion($moduleVersion);
        $response->setSupportedOperationFamilies([
            SafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
        ]);

        return $response;
    }
}
