<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use DateTimeImmutable;

final readonly class AdobeRemoteCatalogItem
{
    public function __construct(
        public int $entityId,
        public ?string $sku,
        public ?string $name,
        public ?string $typeId,
        public ?string $status,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
