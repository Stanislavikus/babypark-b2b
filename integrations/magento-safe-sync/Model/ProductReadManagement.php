<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model;

use B2BPlatform\MagentoSafeSync\Api\Data\ProductReadResponseInterface;
use B2BPlatform\MagentoSafeSync\Api\ProductReadManagementInterface;
use B2BPlatform\MagentoSafeSync\Model\Data\ProductReadResponseFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

final class ProductReadManagement implements ProductReadManagementInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly MetadataPool $metadataPool,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ProductReadResponseFactory $responseFactory,
        private readonly GaleraSessionScope $galeraSessionScope,
    ) {}

    public function readProduct(int $logicalEntityId, string $expectedSku): ProductReadResponseInterface
    {
        try {
            return $this->galeraSessionScope->execute(
                fn (): ProductReadResponseInterface => $this->verifyProduct($logicalEntityId, $expectedSku),
            );
        } catch (SafeSyncReadException $exception) {
            throw $this->mapFailure($exception);
        }
    }

    private function verifyProduct(int $logicalEntityId, string $expectedSku): ProductReadResponseInterface
    {
        if ($logicalEntityId <= 0) {
            throw SafeSyncReadException::invalidLogicalEntityId();
        }

        if ($expectedSku === '') {
            throw SafeSyncReadException::invalidExpectedSku();
        }

        $identifierField = $this->metadataPool->getMetadata(ProductInterface::class)->getIdentifierField();

        try {
            $product = $this->productRepository->getById($logicalEntityId, false, null, true);
        } catch (NoSuchEntityException) {
            throw SafeSyncReadException::entityMissing();
        }

        $loadedLogicalEntityId = (int) $product->getData($identifierField);

        if ($loadedLogicalEntityId !== $logicalEntityId) {
            throw SafeSyncReadException::identityMismatch();
        }

        $loadedSku = (string) $product->getSku();

        if ($loadedSku !== $expectedSku) {
            throw SafeSyncReadException::skuMismatch();
        }

        $this->assertNoConflictingLogicalProducts($expectedSku, $logicalEntityId, $identifierField);

        $response = $this->responseFactory->create();
        $response->setLogicalEntityId($loadedLogicalEntityId);
        $response->setSku($loadedSku);
        $response->setTypeId((string) $product->getTypeId());
        $response->setName((string) $product->getName());

        return $response;
    }

    private function assertNoConflictingLogicalProducts(
        string $expectedSku,
        int $logicalEntityId,
        string $identifierField,
    ): void {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['sku']);
        $collection->addAttributeToFilter('sku', ['eq' => $expectedSku]);
        $collection->setPageSize(3);
        $collection->setCurPage(1);

        $matchingLogicalEntityIds = [];

        foreach ($collection as $product) {
            $matchingLogicalEntityIds[] = (int) $product->getData($identifierField);
        }

        $matchingLogicalEntityIds = array_values(array_unique($matchingLogicalEntityIds));

        if ($matchingLogicalEntityIds !== [$logicalEntityId]) {
            throw SafeSyncReadException::ambiguousSku();
        }
    }

    private function mapFailure(SafeSyncReadException $exception): LocalizedException
    {
        return match ($exception->getMessage()) {
            'safe_sync_entity_missing' => new NoSuchEntityException(__('safe_sync_entity_missing'), $exception),
            'safe_sync_invalid_logical_entity_id' => new InputException(__('safe_sync_invalid_logical_entity_id'), $exception),
            'safe_sync_invalid_expected_sku' => new InputException(__('safe_sync_invalid_expected_sku'), $exception),
            default => new LocalizedException(__($exception->getMessage()), $exception),
        };
    }
}
