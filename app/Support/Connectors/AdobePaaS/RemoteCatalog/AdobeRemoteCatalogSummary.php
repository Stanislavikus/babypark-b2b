<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use App\Models\RemoteCatalogSnapshot;

final readonly class AdobeRemoteCatalogSummary
{
    public function __construct(
        public ?RemoteCatalogSnapshot $snapshot,
        public int $totalCount,
        public int $linkedCount,
        public int $remoteOnlyCount,
        public bool $scanRunning,
    ) {}
}
