<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api;

use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteRequestInterface;
use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteResponseInterface;

interface ProductWriteManagementInterface
{
    /**
     * Performs entity-bound simple product mutation using logical entity identity plus exact expected SKU.
     */
    public function writeSimpleProduct(
        int $logicalEntityId,
        ProductWriteRequestInterface $request,
    ): ProductWriteResponseInterface;
}
