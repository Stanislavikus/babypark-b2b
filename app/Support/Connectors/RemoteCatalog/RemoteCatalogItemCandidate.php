<?php

namespace App\Support\Connectors\RemoteCatalog;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class RemoteCatalogItemCandidate
{
    /**
     * @param  list<RemoteCatalogItemCategoryCandidate>  $categories
     * @param  array<string, mixed>|null  $storefrontLocator
     */
    public function __construct(
        public string $remoteIdentifier,
        public ?string $sku = null,
        public ?string $name = null,
        public ?string $remoteType = null,
        public ?string $remoteStatus = null,
        public ?int $externalAttributeSetId = null,
        public ?DateTimeInterface $remoteUpdatedAt = null,
        public ?string $thumbnailLocator = null,
        public ?string $providerBrandFieldKey = null,
        public ?string $providerBrandValue = null,
        public ?string $providerBrandLabel = null,
        public array $categories = [],
        public ?array $storefrontLocator = null,
    ) {
        if (trim($this->remoteIdentifier) === '') {
            throw new InvalidArgumentException('Remote catalogue item identifier must not be empty.');
        }

        if ($this->externalAttributeSetId !== null && $this->externalAttributeSetId < 1) {
            throw new InvalidArgumentException('Remote catalogue external attribute set id must be positive.');
        }

        foreach ($this->categories as $category) {
            if (! $category instanceof RemoteCatalogItemCategoryCandidate) {
                throw new InvalidArgumentException('Remote catalogue categories must contain RemoteCatalogItemCategoryCandidate values only.');
            }
        }
    }
}
