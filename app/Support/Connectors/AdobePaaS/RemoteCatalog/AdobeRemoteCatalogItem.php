<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use DateTimeImmutable;

final readonly class AdobeRemoteCatalogItem
{
    /**
     * @param  list<AdobeRemoteCatalogCategoryLink>  $categoryLinks
     * @param  array<string, mixed>  $customAttributes
     */
    public function __construct(
        public int $entityId,
        public ?string $sku,
        public ?string $name,
        public ?string $typeId,
        public ?string $status,
        public ?int $attributeSetId = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?string $thumbnailLocator = null,
        public array $categoryLinks = [],
        public array $customAttributes = [],
    ) {}
}
