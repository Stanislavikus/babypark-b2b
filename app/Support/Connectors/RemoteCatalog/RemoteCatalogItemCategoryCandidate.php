<?php

namespace App\Support\Connectors\RemoteCatalog;

use InvalidArgumentException;

final readonly class RemoteCatalogItemCategoryCandidate
{
    public function __construct(
        public string $externalCategoryId,
        public ?string $categoryPath = null,
        public ?int $position = null,
    ) {
        if (trim($this->externalCategoryId) === '') {
            throw new InvalidArgumentException('Remote catalogue category identifier must not be empty.');
        }
    }
}
