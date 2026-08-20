<?php

namespace App\Support\Connectors\AdobePaaS\Media;

final readonly class AdobeProductRemoteMediaMetadataEntry
{
    /**
     * @param  list<string>  $types
     */
    public function __construct(
        public int $entryId,
        public string $mediaType,
        public string $file,
        public string $label,
        public int $position,
        public bool $disabled,
        public array $types,
    ) {}
}
