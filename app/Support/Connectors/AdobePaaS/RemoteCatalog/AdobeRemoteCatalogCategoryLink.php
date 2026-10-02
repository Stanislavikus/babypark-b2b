<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

final readonly class AdobeRemoteCatalogCategoryLink
{
    public function __construct(
        public string $categoryId,
        public ?int $position = null,
    ) {}
}
