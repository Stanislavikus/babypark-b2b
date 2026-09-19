<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

final readonly class AdobeRemoteCatalogPage
{
    /** @param list<AdobeRemoteCatalogItem> $items */
    public function __construct(
        public array $items,
        public int $filteredTotalCount,
    ) {}
}
