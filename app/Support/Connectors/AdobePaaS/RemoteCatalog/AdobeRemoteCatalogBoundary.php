<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

final readonly class AdobeRemoteCatalogBoundary
{
    public function __construct(
        public int $totalCount,
        public ?int $maxEntityId,
    ) {}
}
