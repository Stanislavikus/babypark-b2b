<?php

namespace App\Support\Connectors\RemoteCatalog;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class RemoteCatalogItemCandidate
{
    /**
     * @param  array<string, mixed>|null  $storefrontLocator
     */
    public function __construct(
        public string $remoteIdentifier,
        public ?string $sku = null,
        public ?string $name = null,
        public ?string $remoteType = null,
        public ?string $remoteStatus = null,
        public ?DateTimeInterface $remoteUpdatedAt = null,
        public ?string $thumbnailLocator = null,
        public ?array $storefrontLocator = null,
    ) {
        if (trim($this->remoteIdentifier) === '') {
            throw new InvalidArgumentException('Remote catalogue item identifier must not be empty.');
        }
    }
}
