<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api;

use B2BPlatform\MagentoSafeSync\Api\Data\ProductReadResponseInterface;

interface ProductReadManagementInterface
{
    /**
     * Performs entity-bound product verification using logical entity identity plus expected SKU.
     *
     * @param  int  $logicalEntityId  Requested logical product identity.
     * @param  string  $expectedSku  Exact SKU precondition.
     * @return ProductReadResponseInterface Entity-bound product verification result.
     */
    public function readProduct(int $logicalEntityId, string $expectedSku): ProductReadResponseInterface;
}
