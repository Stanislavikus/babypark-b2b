<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;

final class AdobeRemoteCatalogEnumerator
{
    public const int PAGE_SIZE = 200;

    public function __construct(
        private readonly AdobeRemoteCatalogReadClient $readClient,
    ) {}

    public function captureBoundary(AdobePaaSRequestContext $context): AdobeRemoteCatalogBoundary
    {
        $boundary = $this->readClient->captureBoundary($context);

        if ($boundary->totalCount === 0 && $boundary->maxEntityId !== null) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue empty boundary has an unexpected maximum entity id.');
        }

        if ($boundary->totalCount > 0 && ($boundary->maxEntityId === null || $boundary->maxEntityId < 1)) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue non-empty boundary is missing a valid maximum entity id.');
        }

        return $boundary;
    }

    /**
     * @param  callable(list<AdobeRemoteCatalogItem>): void  $consumePage
     */
    public function enumerateWithinBoundary(
        AdobePaaSRequestContext $context,
        AdobeRemoteCatalogBoundary $boundary,
        callable $consumePage,
    ): int {
        if ($boundary->totalCount === 0) {
            return 0;
        }

        $maxEntityId = $boundary->maxEntityId;
        if ($maxEntityId === null) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue boundary is incomplete.');
        }

        $lastSeenEntityId = 0;
        $received = 0;

        while ($received < $boundary->totalCount) {
            $page = $this->readClient->readPage(
                $context,
                $lastSeenEntityId,
                $maxEntityId,
                self::PAGE_SIZE,
            );

            if ($page->items === []) {
                throw new AdobeRemoteCatalogReadException(sprintf(
                    'Adobe remote catalogue enumeration stopped after %d of %d items.',
                    $received,
                    $boundary->totalCount,
                ));
            }

            foreach ($page->items as $item) {
                if ($item->entityId <= $lastSeenEntityId) {
                    throw new AdobeRemoteCatalogReadException('Adobe remote catalogue entity ids are not strictly increasing.');
                }

                if ($item->entityId > $maxEntityId) {
                    throw new AdobeRemoteCatalogReadException('Adobe remote catalogue page escaped the captured upper boundary.');
                }

                $lastSeenEntityId = $item->entityId;
            }

            $received += count($page->items);
            if ($received > $boundary->totalCount) {
                throw new AdobeRemoteCatalogReadException('Adobe remote catalogue enumeration exceeded the captured item count.');
            }

            $consumePage($page->items);
        }

        $finalBoundedCount = $this->readClient->countWithinBoundary($context, $maxEntityId);
        if ($finalBoundedCount !== $boundary->totalCount) {
            throw new AdobeRemoteCatalogReadException(sprintf(
                'Adobe remote catalogue bounded count changed from %d to %d during enumeration.',
                $boundary->totalCount,
                $finalBoundedCount,
            ));
        }

        return $received;
    }
}
