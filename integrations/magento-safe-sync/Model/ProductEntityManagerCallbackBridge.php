<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\EntityManager\CallbackHandler;

final class ProductEntityManagerCallbackBridge
{
    public function __construct(
        private readonly CallbackHandler $callbackHandler,
    ) {}

    public function clearPendingProductCallbacks(): void
    {
        $this->callbackHandler->clear(ProductInterface::class);
    }

    public function processPendingProductCallbacks(): void
    {
        $this->callbackHandler->process(ProductInterface::class);
    }
}
