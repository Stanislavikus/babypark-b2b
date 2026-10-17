<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Plugin\Gallery;

use B2BPlatform\MagentoSafeSync\Model\Media\NonMediaProductWriteScope;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\EntityManager\MetadataPool;

final class UpdateHandlerNonMediaBypassPlugin
{
    public function __construct(
        private readonly NonMediaProductWriteScope $scope,
        private readonly MetadataPool $metadataPool,
    ) {}

    public function aroundExecute(
        object $subject,
        callable $proceed,
        object $product,
        array $arguments = [],
    ): object {
        $identifierField = $this->metadataPool
            ->getMetadata(ProductInterface::class)
            ->getIdentifierField();

        $logicalEntityId = method_exists($product, 'getData')
            ? (int) $product->getData($identifierField)
            : 0;

        if (! $this->scope->isActiveForLogicalEntity($logicalEntityId)) {
            return $proceed($product, $arguments);
        }

        return $product;
    }
}
