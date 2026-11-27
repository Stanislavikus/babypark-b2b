<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;

interface AdobeRemoteCatalogReadClient
{
    public function captureBoundary(AdobePaaSRequestContext $context): AdobeRemoteCatalogBoundary;

    public function readPage(
        AdobePaaSRequestContext $context,
        int $lastSeenEntityId,
        int $maxEntityId,
        int $pageSize,
    ): AdobeRemoteCatalogPage;

    public function countWithinBoundary(AdobePaaSRequestContext $context, int $maxEntityId): int;
}
