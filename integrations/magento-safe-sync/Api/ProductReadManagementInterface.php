<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api;

use B2BPlatform\MagentoSafeSync\Api\Data\ProductReadResponseInterface;

interface ProductReadManagementInterface
{
    /**
     * Performs entity-bound product verification using logical entity identity plus expected SKU.
     */
    public function readProduct(int $logicalEntityId, string $expectedSku): ProductReadResponseInterface;
}
